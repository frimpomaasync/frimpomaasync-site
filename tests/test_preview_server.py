import socket
import subprocess
import sys
import time
import unittest
from pathlib import Path
from urllib.error import URLError
from urllib.request import urlopen


ROOT = Path(__file__).resolve().parents[1]
SERVER = ROOT / "scripts" / "preview_server.py"


def unused_port() -> int:
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        return listener.getsockname()[1]


class PreviewServerTest(unittest.TestCase):
    def setUp(self) -> None:
        self.port = unused_port()
        self.process = subprocess.Popen(
            [
                sys.executable,
                str(SERVER),
                "--bind",
                "127.0.0.1",
                "--port",
                str(self.port),
            ],
            cwd=ROOT,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
        )

        deadline = time.monotonic() + 3
        while time.monotonic() < deadline:
            if self.process.poll() is not None:
                _, error = self.process.communicate()
                self.fail(f"preview server exited before startup: {error.strip()}")
            try:
                with urlopen(
                    f"http://127.0.0.1:{self.port}/index.html",
                    timeout=0.2,
                ):
                    return
            except URLError:
                time.sleep(0.05)

        self.fail("preview server did not start within 3 seconds")

    def tearDown(self) -> None:
        if self.process.poll() is None:
            self.process.terminate()
            self.process.wait(timeout=3)
        if self.process.stdout:
            self.process.stdout.close()
        if self.process.stderr:
            self.process.stderr.close()

    def test_clean_page_routes_open(self) -> None:
        for route in (
            "synkasa",
            "siesie",
            "portfolio",
            "free",
            "fit",
            "synkasa-fit",
            "siesie-application",
        ):
            with self.subTest(route=route):
                with urlopen(
                    f"http://127.0.0.1:{self.port}/{route}",
                    timeout=1,
                ) as response:
                    self.assertEqual(response.status, 200)
                    self.assertEqual(response.headers.get_content_type(), "text/html")


if __name__ == "__main__":
    unittest.main()

"""Local preview for the assessments.

Serves the site folder, applies the two .htaccess rules the pages depend on
(pretty URLs, and .html served for an extension-less path), and stands in for
the two PHP endpoints so the whole client-side flow can be driven end to end
without a PHP runtime on this Mac.

The PHP reply is faked from a query flag so both branches can be exercised:
  /audit-lead.php          -> ok       (visitor copy sent)
  /audit-lead.php?mode=logged -> logged (saved, copy did not send)
  /audit-lead.php?mode=no  -> no       (rejected)
"""
import os
import re
import sys
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer

ROOT = "/Users/nanafrimpongmaa/frimpomaasync-site"
MODE = os.environ.get("LEAD_MODE", "ok")
POSTS = []


class Handler(SimpleHTTPRequestHandler):
    def __init__(self, *a, **kw):
        super().__init__(*a, directory=ROOT, **kw)

    def log_message(self, *a):
        pass

    def handle_one_request(self):
        try:
            super().handle_one_request()
        except (BrokenPipeError, ConnectionResetError):
            # A browser aborting a media fetch is normal and not a failure.
            self.close_connection = True

    def do_POST(self):
        length = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(length).decode("utf-8", "replace")
        path = self.path.split("?")[0]
        if path == "/event.php":
            POSTS.append(("event", body))
            self.send_response(204)
            self.end_headers()
            return
        if path == "/audit-lead.php":
            POSTS.append(("lead", body))
            reply = MODE.encode()
            self.send_response(200)
            self.send_header("Content-Type", "text/plain")
            self.send_header("Content-Length", str(len(reply)))
            self.end_headers()
            self.wfile.write(reply)
            return
        self.send_response(404)
        self.end_headers()

    def do_GET(self):
        if self.path.split("?")[0] == "/__posts":
            out = ("\n\n----\n\n".join(k + ": " + v for k, v in POSTS)).encode()
            self.send_response(200)
            self.send_header("Content-Type", "text/plain; charset=utf-8")
            self.send_header("Content-Length", str(len(out)))
            self.end_headers()
            self.wfile.write(out)
            return
        if self.headers.get("Range") and self.serve_range():
            return
        super().do_GET()

    def serve_range(self):
        """Answer a byte-range request.

        The stock handler does not do this, and without it a browser cannot
        seek inside a video at all: currentTime stays pinned at 0 and every
        caption check reads the first cue forever. Any real host answers
        ranges, so the preview has to as well or it lies about video.
        """
        path = self.translate_path(self.path)
        if not os.path.isfile(path):
            return False
        m = re.match(r"bytes=(\d*)-(\d*)$", self.headers["Range"].strip())
        if not m:
            return False
        size = os.path.getsize(path)
        start_s, end_s = m.group(1), m.group(2)
        if start_s == "":                       # bytes=-500, the last 500 bytes
            if end_s == "":
                return False
            start, end = max(0, size - int(end_s)), size - 1
        else:
            start = int(start_s)
            end = int(end_s) if end_s else size - 1
        if start >= size or start > end:
            self.send_response(416)
            self.send_header("Content-Range", f"bytes */{size}")
            self.end_headers()
            return True
        end = min(end, size - 1)
        length = end - start + 1
        self.send_response(206)
        self.send_header("Content-Type", self.guess_type(path))
        self.send_header("Content-Range", f"bytes {start}-{end}/{size}")
        self.send_header("Content-Length", str(length))
        self.send_header("Accept-Ranges", "bytes")
        self.end_headers()
        with open(path, "rb") as f:
            f.seek(start)
            remaining = length
            while remaining > 0:
                chunk = f.read(min(64 * 1024, remaining))
                if not chunk:
                    break
                try:
                    self.wfile.write(chunk)
                except (BrokenPipeError, ConnectionResetError):
                    # A browser that has seen enough closes the socket. Normal.
                    return True
                remaining -= len(chunk)
        return True

    def translate_path(self, path):
        real = super().translate_path(path)
        # The host serves /page as page.html. Reproduce that here so the links
        # on the page behave the way they do live.
        if not os.path.exists(real) and os.path.exists(real + ".html"):
            return real + ".html"
        return real


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8899
    ThreadingHTTPServer(("127.0.0.1", port), Handler).serve_forever()

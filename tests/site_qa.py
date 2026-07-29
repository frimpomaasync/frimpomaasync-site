import os
import re
import sys
from html.parser import HTMLParser
from pathlib import Path
from typing import Optional
from urllib.parse import urlparse
from urllib.request import urlopen

ROOT = Path(__file__).resolve().parents[1]
BASE = "http://127.0.0.1:4173"
PLAYWRIGHT_EXECUTABLE = os.environ.get("PLAYWRIGHT_EXECUTABLE_PATH")
PUBLIC_PAGES = [
    "index.html",
    "synkasa.html",
    "siesie.html",
    "portfolio.html",
    "free.html",
    "fit.html",
    "synkasa-fit.html",
    "siesie-application.html",
    "fit-thanks.html",
]


class LinkParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.hrefs: list[str] = []

    def handle_starttag(
        self, tag: str, attrs: list[tuple[str, Optional[str]]]
    ) -> None:
        if tag != "a":
            return
        href = dict(attrs).get("href")
        if href:
            self.hrefs.append(href)


def resolve_local_file(current_file: Path, href: str) -> Optional[Path]:
    parsed = urlparse(href)
    if parsed.scheme or parsed.netloc or href.startswith(("mailto:", "tel:")):
        return None

    if not parsed.path:
        return current_file

    path = parsed.path
    if path == "/":
        return ROOT / "index.html"

    candidate = ROOT / path.lstrip("/")
    if candidate.is_dir():
        return candidate / "index.html"
    if candidate.is_file():
        return candidate

    html_candidate = ROOT / f"{path.lstrip('/')}.html"
    if html_candidate.is_file():
        return html_candidate
    return candidate


def run_static() -> None:
    checked_links = 0
    for filename in PUBLIC_PAGES:
        page_file = ROOT / filename
        parser = LinkParser()
        parser.feed(page_file.read_text())

        for href in parser.hrefs:
            target = resolve_local_file(page_file, href)
            if target is None:
                continue
            checked_links += 1
            assert target.is_file(), f"{filename}: missing target for {href}"

            fragment = urlparse(href).fragment
            if fragment:
                target_html = target.read_text()
                pattern = rf"""id=["']{re.escape(fragment)}["']"""
                assert re.search(pattern, target_html), (
                    f"{filename}: missing #{fragment} in {target.name}"
                )

    print(
        f"static site QA passed: {len(PUBLIC_PAGES)} pages, "
        f"{checked_links} internal links"
    )


def local_target(href: str) -> Optional[str]:
    parsed = urlparse(href)
    if parsed.scheme or href.startswith(("mailto:", "tel:", "#")):
        return None

    path = parsed.path or "/"
    if path == "/":
        return f"{BASE}/index.html"

    candidate = ROOT / path.lstrip("/")
    if candidate.is_dir():
        return f"{BASE}{path.rstrip('/')}/index.html"
    if candidate.is_file():
        return f"{BASE}{path}"
    html_candidate = ROOT / f"{path.lstrip('/')}.html"
    if html_candidate.is_file():
        return f"{BASE}{path}.html"
    return f"{BASE}{path}"


def assert_internal_links(page) -> None:
    hrefs = page.locator("a[href]").evaluate_all(
        "(links) => links.map((link) => link.getAttribute('href'))"
    )
    for href in hrefs:
        target = local_target(href)
        if not target:
            continue
        with urlopen(target) as response:
            assert response.status == 200, f"{href} returned {response.status}"


def assert_no_overflow(page, width: int) -> None:
    size = page.evaluate(
        "() => ({scroll: document.documentElement.scrollWidth, inner: innerWidth})"
    )
    assert size["inner"] == width
    assert size["scroll"] <= size["inner"], size


def run() -> None:
    from playwright.sync_api import sync_playwright

    console_errors: list[str] = []
    page_errors: list[str] = []

    with sync_playwright() as playwright:
        launch_options = {
            "headless": True,
            "args": [
                "--no-sandbox",
                "--disable-dev-shm-usage",
                "--disable-breakpad",
                "--disable-crash-reporter",
                "--no-first-run",
            ],
        }
        if PLAYWRIGHT_EXECUTABLE:
            launch_options["executable_path"] = PLAYWRIGHT_EXECUTABLE
        browser = playwright.chromium.launch(**launch_options)
        page = browser.new_page(viewport={"width": 1280, "height": 900})
        page.on(
            "console",
            lambda message: console_errors.append(message.text)
            if message.type == "error"
            else None,
        )
        page.on("pageerror", lambda error: page_errors.append(str(error)))

        page.goto(f"{BASE}/index.html", wait_until="networkidle")
        assert page.locator("#page-leak-tool").is_visible()
        page.locator("[data-leak='missed']").click()
        assert "front desk" in page.locator("#leak-heading").inner_text().lower()
        assert page.locator("#leak-path").get_attribute("href") == "/synkasa"
        assert_internal_links(page)
        assert_no_overflow(page, 1280)

        page.goto(f"{BASE}/synkasa.html", wait_until="networkidle")
        page.locator("#calc-inquiries").fill("10")
        page.locator("#calc-missed").fill("25")
        page.locator("#calc-value").fill("400")
        page.locator("#calc-booking").fill("50")
        page.get_by_role("button", name="Calculate my scenario").click()
        assert page.locator("#calc-output").inner_text() == "$2,000"
        assert page.locator("#calc-at-risk").inner_text() == "10"
        assert (
            page.locator("#calc-formula").inner_text()
            == "10 × 4 weeks × 25% at risk × 50% booked × $400"
        )
        page.locator("#calc-inquiries").fill("")
        page.get_by_role("button", name="Calculate my scenario").click()
        assert page.locator("#calc-error").inner_text()
        assert_no_overflow(page, 1280)

        page.goto(f"{BASE}/siesie.html", wait_until="networkidle")
        assert page.locator(".role-card").count() == 5
        assert page.get_by_text("Account management", exact=True).count() >= 1
        assert page.get_by_text("Reporting", exact=True).count() >= 1
        checks = page.locator(".audit-check")
        for index in range(4):
            checks.nth(index).check()
        page.get_by_role("button", name="Show my first fix").click()
        assert page.locator("#audit-label").inner_text().startswith("4 of 5")
        assert (
            page.locator("#audit-link").get_attribute("href")
            == "/siesie-application"
        )
        assert_no_overflow(page, 1280)

        page.goto(f"{BASE}/synkasa-fit.html", wait_until="networkidle")
        assert page.locator("form[name='synkasa-fit']").count() == 1
        page.route(
            "https://formspree.io/**",
            lambda route: route.fulfill(status=500, body="failed"),
        )
        page.locator("[name='name']").fill("Test Owner")
        page.locator("[name='email']").fill("owner@example.com")
        page.locator("[name='business_name']").fill("Test Service")
        page.locator("[name='business_type']").fill("Cleaning")
        page.locator("[name='contact_channels']").select_option(label="Calls")
        page.locator("[name='weekly_inquiries']").select_option(label="6 to 15")
        page.locator("[name='main_problem']").fill("Calls wait while I am working.")
        page.locator("[name='booking_method']").fill("I write jobs in a calendar.")
        page.locator("[name='desired_result']").fill("Every caller gets an answer.")
        page.locator("[name='implementation_timing']").select_option(
            label="Within 30 days"
        )
        page.get_by_role("button", name="Send my SynKasa fit").click()
        page.locator("[data-form-status]").get_by_text(
            "Your answers did not send", exact=False
        ).wait_for()
        assert page.get_by_role("button", name="Send my SynKasa fit").is_enabled()

        page.goto(f"{BASE}/siesie-application.html", wait_until="networkidle")
        assert page.locator("form[name='siesie-application']").count() == 1
        required_names = page.locator("[required]").evaluate_all(
            "(fields) => fields.map((field) => field.getAttribute('name'))"
        )
        assert "team_size" in required_names
        assert "monthly_volume" in required_names
        assert "investment_readiness" in required_names

        page.goto(f"{BASE}/fit-thanks.html?source=siesie", wait_until="networkidle")
        assert "back office" in page.locator("[data-confirm-heading]").inner_text()

        mobile = browser.new_page(viewport={"width": 390, "height": 844})
        for path in ("index.html", "synkasa.html", "siesie.html", "fit.html"):
            mobile.goto(f"{BASE}/{path}", wait_until="networkidle")
            assert_no_overflow(mobile, 390)
        mobile.close()

        for filename in PUBLIC_PAGES:
            page.goto(f"{BASE}/{filename}", wait_until="networkidle")
            first_link = page.locator("a[href]").first
            first_link.focus()
            outline = first_link.evaluate(
                "(node) => getComputedStyle(node).outlineStyle"
            )
            assert outline != "none", f"No focus outline on {filename}"

        browser.close()

    assert not console_errors, console_errors
    assert not page_errors, page_errors


if __name__ == "__main__":
    if "--static" in sys.argv:
        run_static()
    else:
        run()
        print("site browser QA passed")

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


def assert_cinematic_nav_overlay(page) -> None:
    state = page.evaluate(
        """() => {
            const nav = document.getElementById("fs-nav");
            const hero = document.querySelector("[data-cinematic-hero]");
            const media = document.querySelector(".cinematic-media");
            const colorValue = (color) => {
                const values = color.match(/[\\d.]+/g).map(Number);
                return { channels: values.slice(0, 3), alpha: values[3] ?? 1 };
            };
            const luminance = (color) => {
                const channels = colorValue(color).channels;
                const normalized = channels.map((channel) => {
                    const value = channel / 255;
                    return value <= .04045
                        ? value / 12.92
                        : ((value + .055) / 1.055) ** 2.4;
                });
                return .2126 * normalized[0] + .7152 * normalized[1] + .0722 * normalized[2];
            };
            const contrast = (foreground, background) => {
                const high = Math.max(luminance(foreground), luminance(background));
                const low = Math.min(luminance(foreground), luminance(background));
                return (high + .05) / (low + .05);
            };
            const navRect = nav.getBoundingClientRect();
            const heroRect = hero.getBoundingClientRect();
            const mediaRect = media.getBoundingClientRect();
            const controls = Array.from(
                nav.querySelectorAll("a, button"),
                (control) => {
                    const style = getComputedStyle(control);
                    const rect = control.getBoundingClientRect();
                    return {
                        width: rect.width,
                        height: rect.height,
                        backingAlpha: colorValue(style.backgroundColor).alpha,
                        contrast: contrast(style.color, style.backgroundColor),
                    };
                },
            );
            return {
                overlapsHero: navRect.top < heroRect.bottom && navRect.bottom > heroRect.top,
                overlapsMedia: navRect.top < mediaRect.bottom && navRect.bottom > mediaRect.top,
                controls,
            };
        }"""
    )
    assert state["overlapsHero"], state
    assert state["overlapsMedia"], state
    assert state["controls"], state
    assert all(control["width"] >= 44 for control in state["controls"]), state
    assert all(control["height"] >= 44 for control in state["controls"]), state
    assert all(control["backingAlpha"] == 1 for control in state["controls"]), state
    assert all(control["contrast"] >= 4.5 for control in state["controls"]), state


def assert_cinematic_mobile_clearance(page) -> None:
    state = page.evaluate(
        """() => {
            const nav = document.getElementById("fs-nav").getBoundingClientRect();
            const hero = document.querySelector("[data-cinematic-hero]").getBoundingClientRect();
            const media = document.querySelector(".cinematic-media").getBoundingClientRect();
            const content = document.querySelector(".cinematic-content").getBoundingClientRect();
            return {
                overlapsHero: nav.top < hero.bottom && nav.bottom > hero.top,
                overlapsMedia: nav.top < media.bottom && nav.bottom > media.top,
                clearance: content.top - nav.bottom,
            };
        }"""
    )
    assert state["overlapsHero"], state
    assert state["overlapsMedia"], state
    assert state["clearance"] >= 24, state


def contrast_ratio(foreground: str, background: str) -> float:
    def channels(color: str) -> tuple[list[float], float]:
        values = re.findall(r"[\d.]+", color)
        assert len(values) >= 3, color
        return [float(value) / 255 for value in values[:3]], (
            float(values[3]) if len(values) > 3 else 1
        )

    def luminance(color: str) -> float:
        rgb, alpha = channels(color)
        backdrop, _ = channels(background)
        linear = [
            value / 12.92 if value <= .04045 else ((value + .055) / 1.055) ** 2.4
            for value in [
                channel * alpha + backdrop[index] * (1 - alpha)
                for index, channel in enumerate(rgb)
            ]
        ]
        return .2126 * linear[0] + .7152 * linear[1] + .0722 * linear[2]

    high, low = sorted((luminance(foreground), luminance(background)), reverse=True)
    return (high + .05) / (low + .05)


def assert_non_overlapping(rectangles: list[dict]) -> None:
    for index, current in enumerate(rectangles):
        for other in rectangles[index + 1 :]:
            overlaps = (
                current["left"] < other["right"]
                and current["right"] > other["left"]
                and current["top"] < other["bottom"]
                and current["bottom"] > other["top"]
            )
            assert not overlaps, (current, other)


def choose_homepage_leak_with_mouse_and_keyboard(page) -> None:
    missed = page.locator("[data-leak='missed']")
    assert missed.is_visible()
    assert missed.is_enabled()
    assert missed.evaluate("(node) => getComputedStyle(node).pointerEvents") != "none"
    missed.focus()
    page.keyboard.press("Enter")
    assert "front desk" in page.locator("#leak-heading").inner_text().lower()

    followup = page.locator("[data-leak='followup']")
    box = followup.bounding_box()
    assert box, "The follow-up leak choice needs a visible pointer target"
    page.mouse.click(box["x"] + box["width"] / 2, box["y"] + box["height"] / 2)
    assert "lead goes quiet" in page.locator("#leak-heading").inner_text().lower()


def assert_homepage_cinematic_contract(browser) -> None:
    page = browser.new_page(viewport={"width": 1280, "height": 900})
    page.goto(f"{BASE}/index.html", wait_until="domcontentloaded")

    hero = page.locator("[data-cinematic-hero]")
    media = page.locator(".cinematic-media")
    tool = page.locator("#page-leak-tool")
    paper_rise = page.locator(".paper-rise").first
    assert page.locator("body[data-cinematic]").count() == 1
    assert hero.is_visible()
    assert media.is_visible()
    assert tool.is_visible()
    assert media.get_attribute("src") == "/assets/hero-scene-wide.jpg"
    assert media.get_attribute("width") == "2400"
    assert media.get_attribute("height") == "1024"
    assert media.get_attribute("fetchpriority") == "high"
    choose_homepage_leak_with_mouse_and_keyboard(page)
    page.wait_for_function(
        "() => { const image = document.querySelector('.cinematic-media'); return image.complete && image.naturalWidth > 0; }"
    )
    assert paper_rise.is_visible()
    assert hero.evaluate(
        "(node) => node.nextElementSibling.classList.contains('paper-rise')"
    )

    geometry = page.evaluate(
        """() => {
            const rect = (selector) => {
                const value = document.querySelector(selector).getBoundingClientRect();
                return { left: value.left, right: value.right, top: value.top,
                    bottom: value.bottom, width: value.width, height: value.height };
            };
            const image = document.querySelector('.cinematic-media');
            const tool = document.querySelector('#page-leak-tool');
            const message = document.querySelector('.hero-message');
            const actions = Array.from(
                document.querySelectorAll('[data-cinematic-hero] .actions a, [data-cinematic-hero] .actions button'),
                (action) => {
                    const style = getComputedStyle(action);
                    return { color: style.color, background: style.backgroundColor };
                },
            );
            const text = Array.from(
                document.querySelectorAll('[data-cinematic-hero] .hero-message .eyebrow, [data-cinematic-hero] h1, [data-cinematic-hero] .hero-copy'),
                (item) => getComputedStyle(item).color,
            );
            const toolText = [
                ...Array.from(document.querySelectorAll('#page-leak-tool .screen-top, #page-leak-tool .screen-top .status, #page-leak-tool .choice'), (item) => ({
                    color: getComputedStyle(item).color,
                    background: 'rgb(255, 255, 255)',
                })),
                ...Array.from(document.querySelectorAll('#page-leak-tool .result .mono, #page-leak-tool .result h3, #page-leak-tool .result p, #page-leak-tool .result a'), (item) => ({
                    color: getComputedStyle(item).color,
                    background: getComputedStyle(document.querySelector('#leak-output')).backgroundColor,
                })),
            ];
            return {
                hero: rect('[data-cinematic-hero]'),
                image: rect('.cinematic-media'),
                message: rect('.hero-message'),
                tool: rect('#page-leak-tool'),
                objectFit: getComputedStyle(image).objectFit,
                text,
                actions,
                toolText,
            };
        }"""
    )
    assert geometry["image"]["left"] <= geometry["hero"]["left"]
    assert geometry["image"]["right"] >= geometry["hero"]["right"]
    assert geometry["image"]["top"] <= geometry["hero"]["top"]
    assert geometry["image"]["bottom"] >= geometry["hero"]["bottom"]
    assert geometry["objectFit"] == "cover"
    assert geometry["message"]["left"] < geometry["tool"]["left"]
    assert geometry["tool"]["width"] <= 470
    assert all(
        contrast_ratio(color, "rgb(8, 11, 24)") >= 4.5
        for color in geometry["text"]
    ), geometry
    assert all(
        contrast_ratio(action["color"], action["background"]) >= 4.5
        for action in geometry["actions"]
    ), geometry
    assert all(
        contrast_ratio(item["color"], item["background"]) >= 4.5
        for item in geometry["toolText"]
    ), geometry
    assert_no_overflow(page, 1280)
    page.close()

    mobile = browser.new_page(viewport={"width": 390, "height": 844})
    mobile.goto(f"{BASE}/index.html", wait_until="domcontentloaded")
    mobile_state = mobile.evaluate(
        """() => {
            const box = (selector) => {
                const rect = document.querySelector(selector).getBoundingClientRect();
                return { left: rect.left, right: rect.right, top: rect.top,
                    bottom: rect.bottom, width: rect.width, height: rect.height };
            };
            const trust = document.querySelector('.hero-trust');
            return {
                nav: box('#fs-nav'),
                message: box('.hero-message'),
                tool: box('#page-leak-tool'),
                toolPosition: getComputedStyle(document.querySelector('#page-leak-tool')).position,
                objectPosition: getComputedStyle(document.querySelector('.cinematic-media')).objectPosition,
                trust: box('.hero-trust'),
                trustItems: Array.from(trust.children, (item) => {
                    const rect = item.getBoundingClientRect();
                    return { left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom };
                }),
            };
        }"""
    )
    assert mobile_state["message"]["top"] - mobile_state["nav"]["bottom"] >= 24
    assert mobile_state["tool"]["top"] >= mobile_state["message"]["bottom"]
    assert mobile_state["toolPosition"] != "absolute"
    assert mobile_state["objectPosition"] in {"62% 50%", "62% center"}
    assert len({item["top"] for item in mobile_state["trustItems"]}) >= 2
    assert all(
        item["left"] >= mobile_state["trust"]["left"]
        and item["right"] <= mobile_state["trust"]["right"]
        for item in mobile_state["trustItems"]
    ), mobile_state
    assert_non_overlapping(mobile_state["trustItems"])
    choose_homepage_leak_with_mouse_and_keyboard(mobile)
    assert_no_overflow(mobile, 390)
    mobile.close()


def assert_homepage_reduced_motion_contract(page) -> None:
    page.goto(f"{BASE}/index.html", wait_until="domcontentloaded")
    media = page.locator(".cinematic-media")
    assert page.locator("[data-cinematic-hero]").is_visible()
    assert page.locator("#page-leak-tool").is_visible()
    assert media.evaluate("(node) => getComputedStyle(node).animationName") == "none"
    assert media.evaluate("(node) => getComputedStyle(node).transform") == "none"
    choose_homepage_leak_with_mouse_and_keyboard(page)


def run_foundation() -> None:
    from playwright.sync_api import sync_playwright

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(
            headless=True,
            args=[
                "--no-sandbox",
                "--disable-dev-shm-usage",
                "--disable-breakpad",
                "--disable-crash-reporter",
                "--no-first-run",
            ],
            executable_path=PLAYWRIGHT_EXECUTABLE,
        ) if PLAYWRIGHT_EXECUTABLE else playwright.chromium.launch(
            headless=True,
            args=[
                "--no-sandbox",
                "--disable-dev-shm-usage",
                "--disable-breakpad",
                "--disable-crash-reporter",
                "--no-first-run",
            ],
        )

        page = browser.new_page(viewport={"width": 1280, "height": 900})
        page.goto(f"{BASE}/tests/fixtures/cinematic.html", wait_until="networkidle")

        assert page.locator(".cinematic-media").evaluate(
            "(node) => getComputedStyle(node).animationName"
        ) == "cinematic-settle"
        assert page.locator(".story-visual").evaluate(
            "(node) => getComputedStyle(node).position"
        ) == "sticky"
        assert float(page.locator(".story-step").nth(1).evaluate(
            "(node) => getComputedStyle(node).opacity"
        )) < 1
        nav = page.locator("#fs-nav")
        assert nav.evaluate(
            "(node) => getComputedStyle(node).backgroundColor"
        ) == "rgba(0, 0, 0, 0)"
        assert nav.evaluate(
            "(node) => getComputedStyle(node).transitionDuration"
        ) == "0s"
        assert_cinematic_nav_overlay(page)

        page.locator(".paper-rise").scroll_into_view_if_needed()
        page.wait_for_timeout(100)
        assert nav.evaluate(
            "(node) => node.classList.contains('is-past-hero')"
        )
        assert nav.evaluate(
            "(node) => getComputedStyle(node).backgroundColor"
        ) == "rgb(255, 255, 255)"
        assert page.locator("#fs-nav .fs-grid").evaluate(
            "(node) => getComputedStyle(node).paddingTop"
        ) == "12px"

        second = page.locator("[data-story-step]").nth(1)
        second.scroll_into_view_if_needed()
        page.wait_for_timeout(100)
        assert second.evaluate(
            "(node) => node.classList.contains('is-active')"
        )
        mobile = browser.new_page(viewport={"width": 390, "height": 844})
        mobile.goto(f"{BASE}/tests/fixtures/cinematic.html", wait_until="networkidle")
        assert_cinematic_nav_overlay(mobile)
        assert_cinematic_mobile_clearance(mobile)
        mobile.close()
        assert_homepage_cinematic_contract(browser)
        browser.close()

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(
            headless=True,
            args=["--no-sandbox", "--disable-dev-shm-usage"],
            executable_path=PLAYWRIGHT_EXECUTABLE,
        ) if PLAYWRIGHT_EXECUTABLE else playwright.chromium.launch(
            headless=True,
            args=["--no-sandbox", "--disable-dev-shm-usage"],
        )
        reduced = browser.new_context(
            viewport={"width": 1280, "height": 900},
            reduced_motion="reduce",
        )
        page = reduced.new_page()
        page.goto(f"{BASE}/tests/fixtures/cinematic.html", wait_until="networkidle")
        assert page.locator(".cinematic-media").evaluate(
            "(node) => getComputedStyle(node).animationName"
        ) == "none"
        assert_cinematic_nav_overlay(page)
        assert page.locator(".story-visual").evaluate(
            "(node) => getComputedStyle(node).position"
        ) == "static"
        assert page.locator(".story-step").nth(1).evaluate(
            "(node) => getComputedStyle(node).opacity"
        ) == "1"
        assert page.locator("#fs-bar").evaluate(
            "(node) => getComputedStyle(node).transitionDuration"
        ) == "0s"
        page.locator(".paper-rise").scroll_into_view_if_needed()
        page.wait_for_timeout(100)
        assert page.locator("#fs-nav").evaluate(
            "(node) => node.classList.contains('is-past-hero')"
        )
        assert page.locator("#fs-nav").evaluate(
            "(node) => getComputedStyle(node).backgroundColor"
        ) == "rgb(255, 255, 255)"
        typing_animation_names = page.evaluate(
            """() => {
                const input = document.getElementById("fs-chat-inp");
                const send = document.getElementById("fs-chat-send");
                const messages = document.getElementById("fs-chat-msgs");
                input.value = "I need an appointment.";
                send.click();
                const typing = messages.lastElementChild;
                if (!typing || typing.children.length !== 3) return [];
                return Array.from(
                    typing.children,
                    (dot) => getComputedStyle(dot).animationName,
                );
            }"""
        )
        assert typing_animation_names == ["none", "none", "none"]
        assert_homepage_reduced_motion_contract(page)
        reduced.close()
        browser.close()


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
    elif "--foundation" in sys.argv:
        run_foundation()
        print("cinematic foundation browser QA passed")
    else:
        run()
        print("site browser QA passed")

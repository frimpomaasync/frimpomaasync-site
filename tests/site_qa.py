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


def assert_hero_photo_is_painted(page, route: str) -> None:
    """The hero photo must actually reach the screen.

    Checking img.complete only proves the file downloaded. It stayed true while
    a real phone showed a flat ink block, so this reads the rendered pixels: a
    photograph varies, a solid fill does not.
    """
    from io import BytesIO
    from PIL import Image

    hero = page.locator("[data-cinematic-hero]")
    box = hero.bounding_box()
    assert box, f"{route}: no hero box"
    image = Image.open(BytesIO(hero.screenshot())).convert("RGB")
    width, height = image.size
    # Sample the half of the hero the scrim leaves lightest.
    crop = image.crop((int(width * .55), int(height * .1), width, int(height * .9)))
    colors = crop.getcolors(maxcolors=1_000_000) or []
    assert colors, f"{route}: hero crop had no colors"
    distinct = len(colors)
    total = sum(count for count, _ in colors)
    dominant = max(count for count, _ in colors)
    assert distinct >= 200, (
        f"{route}: hero looks like a flat fill, only {distinct} distinct colors"
    )
    assert dominant / total < .5, (
        f"{route}: {dominant / total:.0%} of the hero is one color"
    )


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
            const controls = Array.from(nav.querySelectorAll("a, button"))
                .filter((control) => control.getClientRects().length)
                .map((control) => {
                    const style = getComputedStyle(control);
                    const rect = control.getBoundingClientRect();
                    return {
                        width: rect.width,
                        height: rect.height,
                        backingAlpha: colorValue(style.backgroundColor).alpha,
                        contrast: contrast(style.color, style.backgroundColor),
                    };
                });
            const clipped = Array.from(nav.querySelectorAll("a, button"))
                .filter((control) => control.getClientRects().length)
                .filter((control) => control.scrollWidth > control.clientWidth + 1)
                .map((control) => ({
                    text: control.textContent.trim().slice(0, 40),
                    scrollWidth: control.scrollWidth,
                    clientWidth: control.clientWidth,
                }));
            return {
                overlapsHero: navRect.top < heroRect.bottom && navRect.bottom > heroRect.top,
                overlapsMedia: navRect.top < mediaRect.bottom && navRect.bottom > mediaRect.top,
                controls,
                clipped,
            };
        }"""
    )
    assert state["overlapsHero"], state
    assert state["overlapsMedia"], state
    assert state["controls"], state
    assert not state["clipped"], state["clipped"]
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


def assert_synkasa_cinematic_contract(browser) -> None:
    page = browser.new_page(viewport={"width": 1280, "height": 900})
    page.goto(f"{BASE}/synkasa.html", wait_until="networkidle")

    hero = page.locator("[data-cinematic-hero]")
    media = hero.locator(".cinematic-media")
    stage = page.locator("#synkasa-demo-stage")
    story = page.locator(".sticky-story")
    story_visual = story.locator(".story-visual")
    story_steps = story.locator("[data-story-step]")

    assert page.locator("body[data-cinematic]").count() == 1
    assert hero.is_visible()
    assert media.is_visible()
    assert media.get_attribute("src") == "/assets/hero-scene-wide.jpg"
    assert media.evaluate("(node) => getComputedStyle(node).objectPosition") in {
        "58% 50%",
        "58% center",
    }

    assert stage.is_visible()
    status_rows = stage.locator(".product-status-list > div")
    assert status_rows.count() == 4
    assert [" ".join(row.split()) for row in status_rows.all_inner_texts()] == [
        "01 Inquiry received",
        "02 Question answered",
        "03 Two times offered",
        "04 Follow-up ready",
    ]
    chat_control = stage.locator("[data-fs-chat]")
    assert chat_control.is_visible()
    assert chat_control.is_enabled()
    assert chat_control.evaluate(
        "(node) => getComputedStyle(node).pointerEvents"
    ) != "none"
    chat_control.click()
    assert page.locator("#fs-chat-panel").is_visible()
    page.locator("#fs-chat-x").click()

    assert story.is_visible()
    assert story_steps.count() == 4
    assert story_steps.locator("h3").all_inner_texts() == [
        "Answer",
        "Qualify",
        "Book",
        "Follow up",
    ]
    assert story_visual.evaluate(
        "(node) => getComputedStyle(node).position"
    ) == "sticky"
    assert story_visual.locator("video").is_visible()
    for index in range(4):
        step = story_steps.nth(index)
        step.scroll_into_view_if_needed()
        page.wait_for_timeout(160)
        active = story.locator("[data-story-step].is-active")
        assert active.count() == 1
        assert active.nth(0).evaluate(
            "(node, index) => node === document.querySelectorAll('[data-story-step]')[index]",
            index,
        )

    assert page.locator("#opportunity-form").is_visible()
    assert page.locator("#calc-output").is_visible()
    assert page.locator("#synkasa-script").is_visible()
    for label in ("Soma", "Tiers", "Fit", "Ownership", "FAQ"):
        assert page.locator(f"[data-screen-label='{label}']").is_visible()
    assert page.get_by_text("Live in 7 days, or you don't pay", exact=False).count() >= 1
    fit_link = page.locator("a[href='/synkasa-fit']").first
    assert fit_link.is_visible()
    fit_path = fit_link.get_attribute("href")
    assert fit_path == "/synkasa-fit"
    page.goto(f"{BASE}{fit_path}", wait_until="networkidle")
    fit_form = page.locator("form[name='synkasa-fit']")
    assert fit_form.is_visible()
    assert fit_form.locator("button[type='submit']").is_enabled()
    page.close()

    mobile = browser.new_page(viewport={"width": 390, "height": 844})
    mobile.goto(f"{BASE}/synkasa.html", wait_until="networkidle")
    mobile_state = mobile.evaluate(
        """() => {
            const box = (node) => {
                const rect = node.getBoundingClientRect();
                return { top: rect.top, bottom: rect.bottom };
            };
            const nav = document.getElementById('fs-nav');
            const content = document.querySelector('.cinematic-content');
            const visual = document.querySelector('.sticky-story .story-visual');
            const video = visual.querySelector('video');
            const steps = Array.from(document.querySelectorAll('[data-story-step]'));
            const controls = Array.from(
                document.querySelectorAll(
                    '[data-cinematic-hero] .button, #synkasa-demo-stage [data-fs-chat]',
                ),
                (control) => control.getBoundingClientRect().height,
            );
            return {
                nav: box(nav),
                content: box(content),
                visualPosition: getComputedStyle(visual).position,
                video: box(video),
                steps: steps.map(box),
                controls,
            };
        }"""
    )
    assert mobile_state["content"]["top"] - mobile_state["nav"]["bottom"] >= 24
    assert mobile_state["visualPosition"] != "sticky"
    assert mobile_state["video"]["bottom"] <= mobile_state["steps"][0]["top"]
    assert all(
        mobile_state["steps"][index]["bottom"]
        <= mobile_state["steps"][index + 1]["top"]
        for index in range(3)
    )
    assert mobile_state["controls"]
    assert all(height >= 44 for height in mobile_state["controls"])
    assert_no_overflow(mobile, 390)
    mobile.close()


def assert_synkasa_reduced_motion_contract(page) -> None:
    page.goto(f"{BASE}/synkasa.html", wait_until="networkidle")
    stage = page.locator("#synkasa-demo-stage")
    story = page.locator(".sticky-story")
    media = page.locator("[data-cinematic-hero] .cinematic-media")

    assert stage.is_visible()
    assert story.locator("[data-story-step]").count() == 4
    assert media.evaluate("(node) => getComputedStyle(node).animationName") == "none"
    assert story.locator(".story-visual").evaluate(
        "(node) => getComputedStyle(node).position"
    ) != "sticky"
    assert all(
        float(opacity) == 1
        for opacity in story.locator("[data-story-step]").evaluate_all(
            "(steps) => steps.map((step) => getComputedStyle(step).opacity)"
        )
    )
    chat_control = stage.locator("[data-fs-chat]")
    assert chat_control.is_enabled()
    chat_control.click()
    assert page.locator("#fs-chat-panel").is_visible()
    page.locator("#fs-chat-x").click()


def assert_siesie_cinematic_contract(browser) -> None:
    page = browser.new_page(viewport={"width": 1280, "height": 900})
    page.goto(f"{BASE}/siesie.html", wait_until="networkidle")

    hero = page.locator("[data-cinematic-hero]")
    media = hero.locator(".cinematic-media")
    role_panel = hero.locator(".role-list")
    role_rows = role_panel.locator(".role-row")
    expected_roles = [
        "Scheduling",
        "Money",
        "Coordination",
        "Account management",
        "Reporting",
    ]

    assert page.locator("body[data-cinematic]").count() == 1
    assert hero.is_visible()
    assert media.is_visible()
    assert media.get_attribute("src") == "/assets/siesie-hero.jpg"
    assert media.get_attribute("width") == "1536"
    assert media.get_attribute("height") == "1024"
    assert media.get_attribute("fetchpriority") == "high"
    page.wait_for_function(
        """() => {
            const image = document.querySelector(
                '[data-cinematic-hero] .cinematic-media',
            );
            return image.complete
                && image.naturalWidth === 1536
                && image.naturalHeight === 1024;
        }"""
    )
    assert media.evaluate("(node) => getComputedStyle(node).objectPosition") in {
        "50% 50%",
        "center center",
    }

    assert role_panel.is_visible()
    assert role_rows.count() == 5
    assert all(row.is_visible() for row in role_rows.all())
    assert role_rows.locator("strong").all_inner_texts() == expected_roles
    panel_state = role_panel.evaluate(
        """(panel) => ({
            background: getComputedStyle(panel).backgroundColor,
            text: Array.from(panel.querySelectorAll('strong, p'),
                (item) => getComputedStyle(item).color),
        })"""
    )
    assert panel_state["background"] == "rgb(16, 20, 38)"
    assert all(
        contrast_ratio(color, panel_state["background"]) >= 4.5
        for color in panel_state["text"]
    ), panel_state

    story = page.locator(".sticky-story")
    story_visual = story.locator(".story-visual")
    cards = story.locator(".role-card")
    assert story.is_visible()
    assert cards.count() == 5
    assert cards.locator("h3").all_inner_texts() == expected_roles
    assert story_visual.evaluate(
        "(node) => getComputedStyle(node).position"
    ) == "sticky"
    for index in range(5):
        page.evaluate(
            """(index) => {
                const card = document.querySelectorAll(
                    '.sticky-story .role-card',
                )[index];
                const top = card.getBoundingClientRect().top + scrollY;
                window.scrollTo({ top: top - (innerHeight * .42), behavior: 'instant' });
            }""",
            index,
        )
        page.wait_for_function(
            """(index) => document.querySelectorAll(
                '.sticky-story .role-card',
            )[index].classList.contains('is-active')""",
            arg=index,
        )
        active = story.locator(".role-card.is-active")
        assert active.count() == 1
        assert active.nth(0).evaluate(
            "(node, index) => node === document.querySelectorAll(" \
            "'.sticky-story .role-card')[index]",
            index,
        )
    assert role_rows.locator("strong").all_inner_texts() == expected_roles

    checks = page.locator(".audit-check")
    assert checks.count() == 5
    assert all(check.is_visible() for check in checks.all())
    checks.nth(0).focus()
    page.keyboard.press("Space")
    assert checks.nth(0).is_checked()
    for index in range(1, 4):
        checks.nth(index).click()
    page.get_by_role("button", name="Show my first fix").click()
    audit_label = (page.locator("#audit-label").text_content() or "").strip()
    assert audit_label.startswith("4 of 5"), audit_label
    assert page.locator("#audit-link").get_attribute("href") == "/siesie-application"

    workflow = page.locator(".workflow-step")
    assert workflow.count() == 5
    assert workflow.locator("strong").all_inner_texts() == [
        "Work received",
        "Scheduled",
        "Coordinated",
        "Invoiced",
        "Reported",
    ]
    assert page.locator("[data-screen-label='Ownership']").is_visible()
    assert page.locator("[data-screen-label='Build path']").is_visible()
    assert page.get_by_text("Live in 7 days, or you don't pay", exact=False).count() >= 1
    application_link = page.locator("a[href='/siesie-application']").first
    assert application_link.is_visible()
    page.goto(f"{BASE}/siesie-application", wait_until="networkidle")
    application = page.locator("form[name='siesie-application']")
    assert application.is_visible()
    assert application.locator("button[type='submit']").is_enabled()
    page.close()

    tablet = browser.new_page(viewport={"width": 900, "height": 900})
    tablet.goto(f"{BASE}/siesie.html", wait_until="networkidle")
    tablet_story = tablet.locator(".sticky-story")
    assert tablet_story.locator(".story-visual").evaluate(
        "(node) => getComputedStyle(node).position"
    ) != "sticky"
    tablet_grid = tablet_story.evaluate(
        """(story) => {
            const cards = Array.from(story.querySelectorAll('.role-card'));
            return {
                columns: getComputedStyle(story.querySelector('.story-steps')).gridTemplateColumns,
                tops: cards.map((card) => card.getBoundingClientRect().top),
            };
        }"""
    )
    assert len(tablet_grid["columns"].split(" ")) == 2, tablet_grid
    assert tablet_grid["tops"][0] == tablet_grid["tops"][1], tablet_grid
    tablet.close()

    mobile = browser.new_page(viewport={"width": 390, "height": 844})
    mobile.goto(f"{BASE}/siesie.html", wait_until="networkidle")
    mobile_state = mobile.evaluate(
        """() => {
            const box = (node) => {
                const rect = node.getBoundingClientRect();
                return { top: rect.top, bottom: rect.bottom, height: rect.height };
            };
            const nav = document.getElementById('fs-nav');
            const content = document.querySelector('.cinematic-content');
            const cards = Array.from(
                document.querySelectorAll('.sticky-story .role-card'),
            );
            const controls = Array.from(
                document.querySelectorAll('[data-cinematic-hero] .button, #siesie-check .button'),
                (control) => control.getBoundingClientRect().height,
            );
            return {
                nav: box(nav),
                content: box(content),
                columns: getComputedStyle(
                    document.querySelector('.sticky-story .story-steps'),
                ).gridTemplateColumns,
                tops: cards.map((card) => box(card).top),
                controls,
            };
        }"""
    )
    assert mobile_state["content"]["top"] - mobile_state["nav"]["bottom"] >= 24
    assert len(mobile_state["columns"].split(" ")) == 1, mobile_state
    assert all(
        mobile_state["tops"][index] < mobile_state["tops"][index + 1]
        for index in range(4)
    ), mobile_state
    assert mobile_state["controls"]
    assert all(height >= 44 for height in mobile_state["controls"])
    assert_no_overflow(mobile, 390)
    mobile.close()


def assert_siesie_reduced_motion_contract(page) -> None:
    page.goto(f"{BASE}/siesie.html", wait_until="networkidle")
    media = page.locator("[data-cinematic-hero] .cinematic-media")
    story = page.locator(".sticky-story")
    checks = page.locator(".audit-check")

    assert page.locator("[data-cinematic-hero]").is_visible()
    assert media.evaluate("(node) => getComputedStyle(node).animationName") == "none"
    assert media.evaluate("(node) => getComputedStyle(node).transform") == "none"
    assert story.locator(".story-visual").evaluate(
        "(node) => getComputedStyle(node).position"
    ) != "sticky"
    assert all(
        float(opacity) == 1
        for opacity in story.locator(".role-card").evaluate_all(
            "(cards) => cards.map((card) => getComputedStyle(card).opacity)"
        )
    )
    checks.nth(0).focus()
    page.keyboard.press("Space")
    assert checks.nth(0).is_checked()
    page.get_by_role("button", name="Show my first fix").click()
    audit_label = (page.locator("#audit-label").text_content() or "").strip()
    assert audit_label.startswith("1 of 5"), audit_label


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
        page.evaluate(
            """() => {
                const step = document.querySelectorAll('[data-story-step]')[1];
                const top = step.getBoundingClientRect().top + scrollY;
                window.scrollTo({ top: top - (innerHeight * .42), behavior: 'instant' });
            }"""
        )
        page.wait_for_function(
            """() => document.querySelectorAll(
                '[data-story-step]',
            )[1].classList.contains('is-active')"""
        )
        assert second.evaluate(
            "(node) => node.classList.contains('is-active')"
        )
        assert page.locator("[data-story-step].is-active").count() == 1
        mobile = browser.new_page(viewport={"width": 390, "height": 844})
        mobile.goto(f"{BASE}/tests/fixtures/cinematic.html", wait_until="networkidle")
        assert_cinematic_nav_overlay(mobile)
        assert_cinematic_mobile_clearance(mobile)
        mobile.close()
        assert_homepage_cinematic_contract(browser)
        assert_synkasa_cinematic_contract(browser)
        assert_siesie_cinematic_contract(browser)

        # Desktop and phone, on every photographic hero.
        for width, height in ((1280, 900), (390, 844)):
            painted = browser.new_page(viewport={"width": width, "height": height})
            for route in ("/index.html", "/synkasa.html", "/siesie.html"):
                painted.goto(f"{BASE}{route}", wait_until="networkidle")
                painted.wait_for_timeout(900)
                assert_hero_photo_is_painted(painted, f"{route}@{width}")
            painted.close()
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
        assert_synkasa_reduced_motion_contract(page)
        assert_siesie_reduced_motion_contract(page)
        reduced.close()
        browser.close()


RESPONSIVE_ROUTES = [
    "/",
    "/synkasa",
    "/siesie",
    "/portfolio",
    "/free",
    "/fit",
    "/synkasa-fit",
    "/siesie-application",
    "/fit-thanks?source=siesie",
    "/privacy",
    "/terms",
    "/blog/",
]
RESPONSIVE_WIDTHS = [390, 768, 1024, 1280, 1440]


def assert_responsive_routes(browser) -> None:
    """Every public route survives every supported width without overflow."""
    for width in RESPONSIVE_WIDTHS:
        page = browser.new_page(
            viewport={"width": width, "height": 844 if width == 390 else 900}
        )
        for route in RESPONSIVE_ROUTES:
            page.goto(f"{BASE}{route}", wait_until="networkidle")
            assert_no_overflow(page, width)
            if width == 390:
                controls = page.locator(
                    "main a.button, main button.button"
                ).evaluate_all(
                    """(nodes) => nodes
                        .filter((node) => node.getClientRects().length)
                        .map((node) => ({
                            height: node.getBoundingClientRect().height,
                            label: node.textContent.trim().slice(0, 40),
                        }))"""
                )
                for control in controls:
                    assert control["height"] >= 44, f"{route}: {control}"
        page.close()


def run() -> None:
    from playwright.sync_api import sync_playwright

    console_errors: list[str] = []
    page_errors: list[str] = []

    def record_console(message) -> None:
        """Record console errors except the stubbed form-delivery failure."""
        if message.type != "error":
            return
        source = (message.location or {}).get("url", "")
        if "formspree.io" in source and "Failed to load resource" in message.text:
            return
        console_errors.append(f"{message.text} ({source})")

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
        page.on("console", record_console)
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
        audit_label = (page.locator("#audit-label").text_content() or "").strip()
        assert audit_label.startswith("4 of 5"), audit_label
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

        assert_responsive_routes(browser)

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

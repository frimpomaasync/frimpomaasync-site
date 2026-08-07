/* The Trust Room's whole value is that nothing on it overstates what Soft
   Appeals can support. These tests hold it to that: no unsupported credential
   claims, no published breach-notification timeframe, no invented entity
   details, every status label from the declared set, and every request card
   deep-linking to a topic the due-diligence form actually offers. */
import { test } from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

const here = (p) => new URL("../" + p, import.meta.url);
const trust = await readFile(here("soft-appeals-trust-room.html"), "utf8");
const contact = await readFile(here("soft-appeals-contact.html"), "utf8");
const strip = (html) =>
  html.replace(/<style[\s\S]*?<\/style>/g, " ").replace(/<script[\s\S]*?<\/script>/g, " ");
const text = strip(trust).replace(/<[^>]+>/g, " ").replace(/\s+/g, " ");
const lower = text.toLowerCase();

test("no unsupported credential is claimed as held", () => {
  /* Each of these may appear only inside the section that explicitly disclaims
     it. Anywhere else on the page is an overclaim. */
  const notClaimed = trust.slice(trust.indexOf('id="not-claimed"'), trust.indexOf('id="requests"'));
  const elsewhere = strip(trust.replace(notClaimed, " ")).replace(/<[^>]+>/g, " ").toLowerCase();
  for (const badge of ["soc 2", "hitrust", "hipaa certified", "hipaa-certified",
                       "penetration test", "pen test", "aes-256", "end-to-end encrypted",
                       "uptime guarantee", "iso 27001", "fedramp"]) {
    assert.ok(!elsewhere.includes(badge), "“" + badge + "” appears outside the disclaimer section");
  }
});

test("no breach-notification timeframe is published", () => {
  const windows = /\b(24|48|72|96)[\s-]*(hours?|hrs?)\b|\bwithin \d+ (hours|days|business days)\b/i;
  assert.ok(!windows.test(text), "a notification timeframe is stated on the page");
  assert.ok(lower.includes("set in the executed business associate agreement"),
    "timing is deferred to the BAA");
});

test("insurance is stated as under review, never as held", () => {
  assert.ok(!/\$[\d,]+ ?(million|m\b)|policy limit|coverage of \$/i.test(text), "an insurance limit appears");
  assert.ok(lower.includes("cyber liability"), "insurance is addressed rather than skipped");
  const ins = text.slice(text.toLowerCase().indexOf("cyber liability"));
  assert.ok(/under review|being arranged|no coverage or limit is stated/i.test(ins.slice(0, 500)),
    "insurance is not marked as in force");
});

test("the seven unfilled packet blanks are never invented", () => {
  /* Registered entity name, business address, privacy contact, retention
     periods, insurance and notification timing are all things only she can
     answer. None may be asserted here. */
  assert.ok(!/\bLLC\b|\bInc\.|\bL\.L\.C\.|Corporation\b/.test(text), "an entity type is asserted");
  assert.ok(!/\b\d{1,5} [A-Z][a-z]+ (Street|St|Road|Rd|Avenue|Ave|Suite|Ste)\b/.test(text),
    "a street address is published");
  assert.ok(!/\bwe (retain|keep) .{0,40}\b(for|up to) \d+ (years?|months?)/i.test(text),
    "a retention period is published");
  assert.ok(lower.includes("retention periods are being set"), "retention is marked as unsettled");
});

test("the privacy contact is a domain address, never a personal mailbox", () => {
  /* A due-diligence packet for PHI cannot list a consumer mailbox as its
     privacy contact: a reviewer sees it and stops reading. Her word
     2026-08-07 is support@frimpomaasync.com. */
  const emails = [...trust.matchAll(/[a-z0-9._%-]+@[a-z0-9.-]+\.[a-z]{2,}/gi)].map((m) => m[0].toLowerCase());
  assert.ok(emails.length > 0, "a privacy contact is published");
  for (const e of emails) {
    assert.ok(e.endsWith("@frimpomaasync.com"), "off-domain contact address: " + e);
  }
  for (const consumer of ["gmail.com", "yahoo.", "hotmail.", "outlook.com", "icloud.com", "aol."]) {
    assert.ok(!lower.includes(consumer), "consumer mailbox referenced: " + consumer);
  }
  assert.ok(emails.includes("support@frimpomaasync.com"), "the stated contact is published");
  /* Publishing a contact address creates a route people will use, so the page
     must say in the same breath that it is not a PHI channel. */
  const near = text.slice(Math.max(0, lower.indexOf("support@frimpomaasync.com") - 400),
                          lower.indexOf("support@frimpomaasync.com") + 500).toLowerCase();
  assert.ok(near.includes("do not send patient information") || near.includes("never patient information"),
    "the contact address is not paired with a PHI warning");
});

test("every status badge uses one of the four declared labels", () => {
  const badges = [...trust.matchAll(/<span class="st[^"]*">([^<]+)<\/span>/g)].map((m) => m[1].trim());
  const allowed = new Set(["Documented", "In the agreement", "On request", "Under review"]);
  assert.ok(badges.length >= 20, "badges present: " + badges.length);
  for (const b of badges) assert.ok(allowed.has(b), "unexpected status label: " + b);
  for (const label of allowed) assert.ok(badges.includes(label), "status never used: " + label);
});

test("every Under review item names what settles it", () => {
  const items = [...trust.matchAll(/<div class="tr-item">([\s\S]*?)<\/div>\s*<\/div>|<div class="tr-item">([\s\S]*?)(?=<div class="tr-item">|<\/div>\s*<\/div>)/g)]
    .map((m) => m[0])
    .filter((chunk) => chunk.includes("st rev"));
  assert.ok(items.length >= 2, "found the under-review items: " + items.length);
  for (const item of items) {
    const body = item.replace(/<[^>]+>/g, " ").toLowerCase();
    assert.ok(/agreement|before the agreement is signed|raise (it|them)|counsel|attorney|arranged/.test(body),
      "an Under review item does not say what settles it");
  }
});

test("every request card deep-links to a topic the form actually offers", () => {
  const offered = new Set(
    [...contact.matchAll(/name="requested\[\]" value="([^"]+)"/g)].map((m) => m[1])
  );
  assert.ok(offered.size >= 8, "form offers topics: " + offered.size);
  const links = [...trust.matchAll(/href="\/soft-appeals-contact\?request=([^"#]+)#due-diligence"/g)]
    .map((m) => decodeURIComponent(m[1]));
  assert.ok(links.length >= 10, "request cards: " + links.length);
  for (const l of links) assert.ok(offered.has(l), "no matching checkbox for: " + l);
  assert.equal(new Set(links).size, links.length, "no duplicate request cards");
});

test("the deep-link handler matches existing checkboxes rather than injecting", () => {
  assert.ok(contact.includes('input[type=checkbox][name="requested[]"]'),
    "handler targets the real checkbox name");
  assert.ok(contact.includes("if (!hit) return;"), "an unknown value selects nothing");
  assert.ok(!/insertAdjacentHTML|innerHTML\s*=/.test(
    contact.slice(contact.indexOf("Deep links from the Trust Room"))
  ), "handler does not inject markup from the query string");
});

test("the page collects nothing and accepts no uploads", () => {
  assert.ok(!/<form/i.test(trust), "no form on the Trust Room itself");
  assert.ok(!/type="file"/i.test(trust), "no upload field");
  assert.ok(!/<input/i.test(trust), "no input of any kind");
  assert.ok(lower.includes("no patient information"), "states that no PHI is needed");
});

test("the data flow marks where PHI does and does not appear, in the right order", () => {
  const steps = [...trust.matchAll(/<span class="phi( on)?">/g)].map((m) => (m[1] ? "PHI" : "No PHI"));
  assert.equal(steps.length, 9, "nine flow steps");
  assert.deepEqual(steps.slice(0, 4), ["No PHI", "No PHI", "No PHI", "No PHI"],
    "nothing before secure intake carries PHI");
  assert.ok(steps.slice(4).every((s) => s === "PHI"), "everything after intake carries PHI");
});

test("no compliance determination, guarantee or deprecated language", () => {
  /* "guarantee" is checked outside the disclaimer section only, where the
     struck-through "Uptime guarantees" badge legitimately names it to refuse
     it. Anywhere else the word would be a promise. */
  const notClaimed = trust.slice(trust.indexOf('id="not-claimed"'), trust.indexOf('id="requests"'));
  const outside = strip(trust.replace(notClaimed, " ")).replace(/<[^>]+>/g, " ").toLowerCase();
  for (const p of ["we are hipaa compliant", "fully compliant", "guarantee", "guaranteed",
                   "small practice", "independent practice", "winnable", "dead claim",
                   "found money", "free audit", "win probability", "bank-level", "military-grade"]) {
    assert.ok(!outside.includes(p), "no “" + p + "”");
  }
  assert.ok(!/—/.test(trust), "no em dashes");
  assert.ok(!/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}]/u.test(trust), "no emojis");
});

test("legal framing is present and the page defers to the executed agreements", () => {
  assert.ok(lower.includes("is not legal advice"), "carries the not-legal-advice line");
  assert.ok(lower.includes("governed by the executed agreements"), "defers to the agreements");
  assert.ok(lower.includes("nana frimpongmaa") || !lower.includes("frimpomaa "),
    "founder name is spelled correctly where present");
});

test("the Trust Room is reachable from the nav and the security page", async () => {
  const fsnav = await readFile(here("fsnav.js"), "utf8");
  assert.ok(fsnav.includes('["Trust Room", "/soft-appeals-trust-room", "soft-trust"]'), "in the More menu");
  assert.ok(fsnav.includes('return "soft-trust"'), "has its own active key");
  const sec = await readFile(here("soft-appeals-data-security.html"), "utf8");
  assert.ok(sec.includes("/soft-appeals-trust-room"), "linked from data and security practices");
  const sitemap = await readFile(here("sitemap.xml"), "utf8");
  assert.ok(sitemap.includes("soft-appeals-trust-room"), "in the sitemap");
});

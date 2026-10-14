"""Build the demo files from one source (demo/setup.php):

  demo/blueprint.json             public blueprint for playground.wordpress.net (installs the published zip)
  tests/blueprint-local-X.json    local test blueprints for PHP 7.4 and 8.3 (plugin folder mounted)
  tests/blueprint-woo-X.json      the same with WooCommerce and test products, PHP 7.4 and 8.3
  tests/blueprint-multisite.json  a network of 2 sites, plugin not active yet (network activation tests)
  dist/site-answers.zip           the plugin, folder at the zip root, no dev files

Run: python3 demo/build.py [--zip-url URL]
"""
from __future__ import annotations

import argparse
import json
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "site-answers"
PUBLIC_ZIP_URL = "https://slimanechanchoul.com/wp-chatbot/site-answers.zip"
SKIP = {".DS_Store", "Thumbs.db"}


def setup_code() -> str:
    return (ROOT / "demo" / "setup.php").read_text(encoding="utf-8")


def public_blueprint(zip_url: str) -> dict:
    return {
        "$schema": "https://playground.wordpress.net/blueprint-schema.json",
        "meta": {
            "title": "Site Answers demo: Atlas Home",
            "description": "A fictional home-goods store running the Site Answers plugin: a chat that answers from the "
                           "site's own pages, with numbered sources.",
            "author": "slimaneoptic",
            "categories": ["AI", "Chatbot"],
        },
        "landingPage": "/",
        "preferredVersions": {"php": "8.3", "wp": "latest"},
        "features": {"networking": True},
        "login": True,
        "steps": [
            {"step": "installPlugin", "pluginData": {"resource": "url", "url": zip_url}, "options": {"activate": True}},
            {"step": "runPHP", "code": setup_code()},
        ],
    }


def local_blueprint(php: str) -> dict:
    # The Playground CLI takes the PHP version from the blueprint when it is given as a file, so set it here.
    return {
        "landingPage": "/",
        "preferredVersions": {"php": php, "wp": "latest"},
        "login": True,
        "steps": [
            {"step": "activatePlugin", "pluginPath": "site-answers/site-answers.php"},
            {"step": "runPHP", "code": setup_code()},
        ],
    }


def woo_blueprint(php: str) -> dict:
    """Local test only: WooCommerce from wordpress.org, the demo store, then five products and a hidden one."""
    bp = local_blueprint(php)
    bp["steps"] = [{"step": "installPlugin", "pluginData": {"resource": "wordpress.org/plugins", "slug": "woocommerce"},
                    "options": {"activate": True}}] + bp["steps"] + [
        {"step": "runPHP", "code": (ROOT / "tests" / "woo-products.php").read_text(encoding="utf-8")}]
    return bp


def multisite_blueprint() -> dict:
    """Local test only: a network (subdirectories) with a second site /shop/ that has a Returns page; the plugin is
    mounted but not active, so the test can network-activate it."""
    shop = """<?php require_once '/wordpress/wp-load.php';
update_option( 'permalink_structure', '/%postname%/' );
switch_to_blog( 2 );
update_option( 'permalink_structure', '/%postname%/' );
wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Returns',
    'post_content' => '<!-- wp:paragraph --><p>You can return unused items within 30 days of delivery for a full refund.</p><!-- /wp:paragraph -->' ) );
restore_current_blog();
flush_rewrite_rules();
"""
    return {
        "landingPage": "/",
        "preferredVersions": {"php": "8.3", "wp": "latest"},
        "login": True,
        "steps": [
            {"step": "enableMultisite"},
            {"step": "wp-cli", "command": "wp site create --slug=shop --title=Shop"},
            {"step": "runPHP", "code": shop},
        ],
    }


def build_zip(out: Path) -> int:
    out.parent.mkdir(parents=True, exist_ok=True)
    files = sorted(p for p in PLUGIN.rglob("*") if p.is_file() and p.name not in SKIP)
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for p in files:
            z.write(p, "site-answers/" + p.relative_to(PLUGIN).as_posix())
    return out.stat().st_size


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--zip-url", default=PUBLIC_ZIP_URL)
    ap.add_argument("--out", default=str(ROOT / "demo" / "blueprint.json"))
    args = ap.parse_args()
    Path(args.out).write_text(json.dumps(public_blueprint(args.zip_url), indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    for php in ("7.4", "8.3"):
        (ROOT / "tests" / f"blueprint-local-{php}.json").write_text(
            json.dumps(local_blueprint(php), indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    for php in ("7.4", "8.3"):
        (ROOT / "tests" / f"blueprint-woo-{php}.json").write_text(
            json.dumps(woo_blueprint(php), indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    (ROOT / "tests" / "blueprint-multisite.json").write_text(
        json.dumps(multisite_blueprint(), indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    size = build_zip(ROOT / "dist" / "site-answers.zip")
    print(f"blueprint: {args.out}\nlocal blueprints: tests/blueprint-local-*.json, blueprint-woo-*.json, blueprint-multisite.json\n"
          f"zip: dist/site-answers.zip ({size} bytes)")


if __name__ == "__main__":
    main()

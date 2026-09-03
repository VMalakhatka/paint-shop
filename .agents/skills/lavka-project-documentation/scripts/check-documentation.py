#!/usr/bin/env python3
"""Validate Lavka docs and require documentation impact for project changes."""

from __future__ import annotations

import argparse
import fnmatch
import re
import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import unquote


LINK_RE = re.compile(r"\[[^\]]*\]\(([^)]+)\)")
REQUIRED = (
    "README.md",
    "docs/README.md",
    "docs/SYSTEM_OVERVIEW.md",
    "docs/BACKEND_GUIDE.md",
    "docs/OPERATIONS_RUNBOOK.md",
    "docs/BOOTSTRAP_AND_RECOVERY.md",
    "docs/JAVA_DOCKER_RUNTIME.md",
    "docs/SKILLS_CATALOG.md",
    "docs/DOCUMENTATION_POLICY.md",
    "docs/KNOWN_GAPS.md",
    "docs/WHOLESALE_CUSTOMER_GUIDE_UK.md",
)


@dataclass(frozen=True)
class ImpactRule:
    name: str
    source_patterns: tuple[str, ...]
    documentation_patterns: tuple[str, ...]


IMPACT_RULES = (
    ImpactRule(
        "synchronization, reports and Folio projections",
        (
            "wp-content/plugins/lavka-total-sync/**",
            "wp-content/plugins/lavka-sync/**",
            "wp-content/plugins/lavka-price-sync/**",
            "wp-content/plugins/lavka-reports/**",
            "wp-content/mu-plugins/folio-*.php",
            "wp-content/mu-plugins/lavka-*sync*.php",
        ),
        (
            "docs/OPERATIONS_RUNBOOK.md",
            "docs/SYSTEM_OVERVIEW.md",
            "docs/BACKEND_GUIDE.md",
            "docs/api/**",
        ),
    ),
    ImpactRule(
        "media workflow",
        (
            "wp-content/plugins/lavka-product-media-upload/**",
            "wp-content/mu-plugins/lavka-cli-import-media.php",
            "wp-content/plugins/lavka-total-sync/inc/admin-media.php",
            "wp-content/plugins/lavka-total-sync/inc/sync-img.php",
            "wp-content/import-ovh-media.sh",
            "WP_media_cloud.md",
        ),
        (
            "docs/MEDIA_MANAGER_GUIDE_UK.md",
            "docs/OPERATIONS_RUNBOOK.md",
            "docs/api/FOLIO_PRODUCT_MEDIA_API.md",
            "WP_media_cloud.md",
        ),
    ),
    ImpactRule(
        "wholesale customer ordering, warehouse allocation and Folio account views",
        (
            "wp-content/mu-plugins/pc-wholesale-quick-order.php",
            "wp-content/mu-plugins/pc-wholesale-help.php",
            "wp-content/mu-plugins/pc-wholesale-help/**",
            "wp-content/mu-plugins/pc-folio-order-link.php",
            "wp-content/mu-plugins/pc-folio-customer-balance.php",
            "wp-content/plugins/paint-core/inc/header-allocation-switcher.php",
            "wp-content/plugins/paint-core/inc/catalog-qty-add-to-cart.php",
            "wp-content/plugins/paint-core/inc/stock-locations-display.php",
            "wp-content/plugins/paint-core/assets/css/catalog-qty.css",
            "wp-content/plugins/paint-core/assets/js/catalog-qty.js",
            "wp-content/plugins/paint-shop-ux/**",
        ),
        ("docs/WHOLESALE_CUSTOMER_GUIDE_UK.md",),
    ),
    ImpactRule(
        "checkout, orders, payments, fiscalization and delivery",
        (
            "wp-content/plugins/paint-core/**",
            "wp-content/plugins/paint-shop-ux/**",
            "wp-content/plugins/pc-order-import-export/**",
            "wp-content/plugins/paint-nova-poshta-multishipping/**",
            "wp-content/plugins/pc-checkbox-fiscalization/**",
            "wp-content/mu-plugins/*order*.php",
            "wp-content/mu-plugins/*checkbox*.php",
            "wp-content/mu-plugins/*wayforpay*.php",
            "wp-content/mu-plugins/*nova*.php",
        ),
        (
            "docs/OPERATIONS_RUNBOOK.md",
            "docs/SITE_USER_GUIDE_UK.md",
            "docs/WHOLESALE_CUSTOMER_GUIDE_UK.md",
            "docs/SYSTEM_OVERVIEW.md",
            "docs/FOLIO_ORDER_JSON_CONTRACT.md",
            "docs/WAYFORPAY_TEST_ACCESS.md",
        ),
    ),
    ImpactRule(
        "application deploy, configuration and recovery",
        (
            "wp-config*.php",
            "wp-content/deploy*.sh",
            "wp-content/deploy_plugins.list",
            "wp-content/update_ops.sh",
            "wp-content/full_backup.sh",
            "wp-content/pull_latest_backup.sh",
        ),
        (
            "docs/BOOTSTRAP_AND_RECOVERY.md",
            "docs/BACKEND_GUIDE.md",
            "docs/JAVA_DOCKER_RUNTIME.md",
            "docs/OPERATIONS_RUNBOOK.md",
            ".agents/skills/lavka-woo/references/deployment-and-configuration.md",
        ),
    ),
    ImpactRule(
        "storefront and account UI",
        (
            "wp-content/themes/generatepress-child/**",
            "wp-content/plugins/role-price/**",
        ),
        (
            "docs/SITE_USER_GUIDE_UK.md",
            "docs/WHOLESALE_CUSTOMER_GUIDE_UK.md",
            "docs/SYSTEM_OVERVIEW.md",
            ".agents/skills/lavka-woo/references/pages-and-ui.md",
        ),
    ),
    ImpactRule(
        "project skill behavior",
        (".agents/skills/*/SKILL.md", ".agents/skills/*/references/**"),
        (
            ".agents/skills/*/SKILL.md",
            ".agents/skills/*/references/**",
            "docs/SKILLS_CATALOG.md",
            "docs/DOCUMENTATION_POLICY.md",
        ),
    ),
)


def markdown_files(root: Path) -> list[Path]:
    files = [root / "README.md"]
    files.extend((root / "docs").rglob("*.md"))
    skills_root = root / ".agents" / "skills"
    for skill_name in ("lavka-woo", "lavka-project-documentation"):
        skill = skills_root / skill_name
        files.append(skill / "SKILL.md")
        references = skill / "references"
        if references.is_dir():
            files.extend(references.rglob("*.md"))
    return sorted({path.resolve() for path in files if path.is_file()})


def git_paths(root: Path, arguments: list[str]) -> set[str]:
    result = subprocess.run(
        ["git", *arguments], cwd=root, check=False, capture_output=True, text=True
    )
    if result.returncode != 0:
        detail = result.stderr.strip() or "git command failed"
        raise RuntimeError(detail)
    return {line.strip() for line in result.stdout.splitlines() if line.strip()}


def changed_paths(root: Path, args: argparse.Namespace) -> set[str]:
    if args.staged:
        return git_paths(root, ["diff", "--cached", "--name-only", "--diff-filter=ACMR"])
    if args.base:
        return git_paths(root, ["diff", "--name-only", "--diff-filter=ACMR", f"{args.base}...HEAD"])
    if args.working_tree:
        paths = git_paths(root, ["diff", "--name-only", "--diff-filter=ACMR"])
        paths.update(
            git_paths(root, ["diff", "--cached", "--name-only", "--diff-filter=ACMR"])
        )
        paths.update(git_paths(root, ["ls-files", "--others", "--exclude-standard"]))
        return paths
    return set(args.changed_file or ())


def matches(path: str, patterns: tuple[str, ...]) -> bool:
    return any(fnmatch.fnmatchcase(path, pattern) for pattern in patterns)


def is_documentation(path: str) -> bool:
    return (
        path == "README.md"
        or path == "WP_media_cloud.md"
        or (path.startswith("docs/") and path.endswith(".md"))
        or (path.startswith(".agents/skills/") and path.endswith(".md"))
        or (path.startswith("wp-content/plugins/") and path.endswith("/README.md"))
    )


def managed_source(path: str) -> bool:
    return (
        path.startswith("wp-content/plugins/")
        or path.startswith("wp-content/mu-plugins/")
        or path.startswith("wp-content/themes/generatepress-child/")
        or path.startswith(".agents/skills/")
        or path.startswith("wp-content/deploy")
        or path
        in {
            "wp-content/update_ops.sh",
            "wp-content/full_backup.sh",
            "wp-content/pull_latest_backup.sh",
            "wp-config.php",
            "wp-config.local.php",
            "wp-config.production.php",
            "wp-config.common.php",
            "wp-config-sample.php",
        }
    )


def same_plugin_readme(source: str, changed: set[str]) -> bool:
    parts = source.split("/")
    if len(parts) < 4 or parts[:2] != ["wp-content", "plugins"]:
        return False
    return f"wp-content/plugins/{parts[2]}/README.md" in changed


def documentation_impact_errors(changed: set[str]) -> list[str]:
    if not changed:
        return []

    errors: list[str] = []
    for rule in IMPACT_RULES:
        sources = sorted(path for path in changed if matches(path, rule.source_patterns))
        if not sources:
            continue
        has_document = any(matches(path, rule.documentation_patterns) for path in changed)
        if not has_document and not any(same_plugin_readme(path, changed) for path in sources):
            examples = ", ".join(sources[:3])
            expected = ", ".join(rule.documentation_patterns)
            errors.append(
                f"documentation impact missing for {rule.name}: {examples}; "
                f"update one relevant document ({expected})"
            )

    unmatched = sorted(
        path
        for path in changed
        if managed_source(path)
        and not is_documentation(path)
        and not any(matches(path, rule.source_patterns) for rule in IMPACT_RULES)
    )
    if unmatched:
        general_docs_changed = any(
            is_documentation(path) and not path.endswith("/SKILL.md") for path in changed
        )
        plugin_readme_changed = any(same_plugin_readme(path, changed) for path in unmatched)
        if not general_docs_changed and not plugin_readme_changed:
            examples = ", ".join(unmatched[:5])
            errors.append(
                "documentation impact missing for other maintained project code: "
                f"{examples}; update the owning plugin README or a relevant docs/*.md"
            )
    return errors


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Validate Markdown and changed-path documentation impact."
    )
    modes = parser.add_mutually_exclusive_group()
    modes.add_argument(
        "--working-tree",
        action="store_true",
        help="check unstaged, staged and untracked working-tree paths",
    )
    modes.add_argument("--staged", action="store_true", help="check staged paths")
    modes.add_argument("--base", metavar="REF", help="check paths from REF to HEAD")
    modes.add_argument(
        "--changed-file",
        action="append",
        help="check an explicit changed path; repeat for tests or orchestration",
    )
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[4]
    errors: list[str] = []

    for relative in REQUIRED:
        if not (root / relative).is_file():
            errors.append(f"missing required document: {relative}")

    checked = 0
    for source in markdown_files(root):
        checked += 1
        content = source.read_text(encoding="utf-8")
        for raw_target in LINK_RE.findall(content):
            target = raw_target.strip().strip("<>")
            if not target or target.startswith(("#", "/", "mailto:")):
                continue
            if "://" in target:
                continue
            target = unquote(target.split("#", 1)[0])
            if not target:
                continue
            resolved = (source.parent / target).resolve()
            if not resolved.exists():
                location = source.relative_to(root)
                errors.append(f"broken link: {location} -> {raw_target}")

    try:
        changed = changed_paths(root, args)
    except RuntimeError as error:
        errors.append(f"cannot determine changed paths: {error}")
        changed = set()
    errors.extend(documentation_impact_errors(changed))

    if errors:
        print("Documentation validation failed:")
        for error in errors:
            print(f"- {error}")
        return 1

    mode = "structure and links"
    if args.working_tree:
        mode = f"working-tree impact ({len(changed)} paths)"
    elif args.staged:
        mode = f"staged impact ({len(changed)} paths)"
    elif args.base:
        mode = f"impact since {args.base} ({len(changed)} paths)"
    elif args.changed_file:
        mode = f"explicit impact ({len(changed)} paths)"
    print(f"Documentation validation passed: {mode}; {checked} Markdown files checked.")
    return 0


if __name__ == "__main__":
    sys.exit(main())

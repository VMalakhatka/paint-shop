#!/usr/bin/env python3
"""Build a public, reviewable map. Does not connect to servers or change a site."""
import argparse
from collections import defaultdict
import hashlib
import html
import json
from pathlib import Path
import re
from urllib.parse import urlsplit


def normalized(name):
    return re.sub(r"\s+", " ", html.unescape(name).strip().casefold())


def ancestry(categories, key, parent):
    result = []
    while key in categories:
        if key in result:
            raise ValueError("Category cycle")
        result.append(key)
        key = int(categories[key][parent])
    if key != 0:
        raise ValueError("Missing category ancestor")
    return result


def target_url(value):
    parsed = urlsplit(value)
    if (parsed.scheme != "https" or parsed.netloc != "kreul.com.ua"
            or not parsed.path.startswith("/") or parsed.query or parsed.fragment
            or re.search(r"[\s\\]|%0[ad]", value, re.I)):
        raise ValueError("Unexpected target URL")
    return value


def build(data):
    public = data["public"]
    permitted = {"products", "unmatched_products", "categories", "product_categories",
                 "aliases", "information", "woo_categories", "woo_relationships"}
    if set(public) != permitted:
        raise ValueError("Expected public catalogue dataset only")
    old = {int(row["category_id"]): row for row in public["categories"]}
    new = {int(row["id"]): row for row in public["woo_categories"]}
    root = [key for key, row in old.items()
            if int(row["parent_id"]) == 0 and normalized(row["name"]) == "каталог"]
    if len(root) != 1:
        raise ValueError("Expected exactly one legacy catalogue root")
    product_ids, products, sku_seen = {}, {}, set()
    for row in public["products"]:
        source = int(row["old_id"])
        sku = row["sku"].strip()
        if source in product_ids or not sku or sku in sku_seen:
            raise ValueError("Duplicate source product/SKU")
        product_ids[source] = int(row["target_id"])
        products[str(source)] = target_url(row["target_url"])
        sku_seen.add(sku)
    old_members, new_members, by_path = defaultdict(set), defaultdict(set), defaultdict(list)
    for key in new:
        chain = ancestry(new, key, "parent")
        by_path[tuple(normalized(new[k]["name"]) for k in reversed(chain))].append(key)
    for row in public["product_categories"]:
        source = int(row["product_id"])
        if source in product_ids:
            for key in ancestry(old, int(row["category_id"]), "parent_id"):
                old_members[key].add(product_ids[source])
    for row in public["woo_relationships"]:
        for key in ancestry(new, int(row["category_id"]), "parent"):
            new_members[key].add(int(row["product_id"]))
    categories, exceptions, evidence = {}, [], []
    for key, row in old.items():
        if str(row["status"]) != "1":
            exceptions.append({"old_id": key, "name": row["name"], "reason": "disabled"})
            continue
        if key == root[0]:
            categories[str(key)] = "https://kreul.com.ua/"
            evidence.append({"old_id": key, "reason": "catalogue_root"})
            continue
        chain = list(reversed(ancestry(old, key, "parent_id")))
        if chain[0] != root[0]:
            raise ValueError("Category outside catalogue")
        path = tuple(normalized(old[k]["name"]) for k in chain[1:])
        candidates = by_path[path]
        verified = [k for k in candidates
                    if old_members[key] and old_members[key] <= new_members[k]]
        chosen, reason = None, None
        if len(candidates) == 1 and (not old_members[key] or verified):
            chosen, reason = candidates[0], "unique_path_and_contents"
        elif len(verified) == 1:
            chosen, reason = verified[0], "duplicate_path_resolved_by_contents"
        if chosen is None:
            exceptions.append({"old_id": key, "name": row["name"], "path": list(path),
                "reason": "no_verified_target", "candidates": candidates,
                "mapped_product_count": len(old_members[key])})
        else:
            categories[str(key)] = target_url(new[chosen]["url"])
            evidence.append({"old_id": key, "target_id": chosen, "reason": reason,
                             "mapped_product_count": len(old_members[key])})
    alias_values = defaultdict(set)
    for row in public["aliases"]:
        if row["keyword"]:
            alias_values[row["keyword"]].add(row["query"])
    aliases = {key: next(iter(values)) for key, values in alias_values.items() if len(values) == 1}
    mapping = {"version": 1, "home": "https://kreul.com.ua/", "products": products,
               "categories": categories, "aliases": aliases}
    report = {"products_mapped": len(products), "products_unmatched": public["unmatched_products"],
              "categories_mapped": len(categories), "categories_unresolved": exceptions,
              "category_evidence": evidence, "duplicate_aliases_omitted":
                  [key for key, values in alias_values.items() if len(values) != 1]}
    return mapping, report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("snapshot", type=Path)
    parser.add_argument("output", type=Path)
    args = parser.parse_args()
    raw = args.snapshot.read_bytes()
    mapping, report = build(json.loads(raw))
    report["source_sha256"] = hashlib.sha256(raw).hexdigest()
    args.output.mkdir(parents=True, exist_ok=True)
    for name, value in (("mapping.json", mapping), ("report.json", report)):
        (args.output / name).write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n")
    print(json.dumps({"products_mapped": report["products_mapped"],
                     "categories_mapped": report["categories_mapped"],
                     "products_unmatched": len(report["products_unmatched"]),
                     "categories_unresolved": len(report["categories_unresolved"])}, ensure_ascii=False))


if __name__ == "__main__":
    main()

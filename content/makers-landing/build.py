#!/usr/bin/env python3
"""Build an editorial WordPress core/html block and a noindex local preview.

No network calls, publishing, form collection, or email sending. Supply the
verified Media Library URLs via --asset-map for a WordPress-ready payload.
"""
import argparse
import html
import json
import re
import shutil
from pathlib import Path
from urllib.parse import quote, urlparse

ROOT = Path(__file__).resolve().parent
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--email', default='shop@paint.dn.ua')
parser.add_argument('--output', type=Path, default=ROOT / 'preview')
parser.add_argument('--asset-map', type=Path)
args = parser.parse_args()
if not re.fullmatch(r'[A-Za-z0-9._+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}', args.email):
    parser.error('Expected a plain email address without query parameters.')
asset_map = json.loads(args.asset_map.read_text()) if args.asset_map else {}
markup = (ROOT / 'page.html').read_text()
css = (ROOT / 'style.css').read_text()
assets = sorted(set(re.findall(r'\{\{asset:([^}]+)\}\}', markup)))
for name in assets:
    url = asset_map.get(name, f'assets/{name}.jpg')
    if args.asset_map and (name not in asset_map or urlparse(url).scheme != 'https'):
        parser.error(f'Missing verified HTTPS attachment URL: {name}')
    markup = markup.replace('{{asset:' + name + '}}', html.escape(url, quote=True))
subject = 'Співпраця з майстром — Лавка Художника'
body = 'Вітаю! Хочу обговорити співпрацю.\n\nМене звати:\nМоя майстерня / сторінка:\nЯ створюю:\nПотрібні матеріали:\nОрієнтовні обсяги:\n\nДякую!'
mailto = f'mailto:{args.email}?subject={quote(subject)}&body={quote(body)}'
markup = markup.replace('{{contact:email}}', html.escape(args.email)).replace('{{contact:mailto}}', html.escape(mailto, quote=True))
if '{{' in markup:
    raise RuntimeError('Unresolved content placeholder')
args.output.mkdir(parents=True, exist_ok=True)
if not args.asset_map:
    shutil.copytree(ROOT / 'assets', args.output / 'assets', dirs_exist_ok=True)
block = '<!-- wp:html -->\n<style>\n' + css + '\n</style>\n' + markup + '\n<!-- /wp:html -->\n'
(args.output / 'wordpress-content.html').write_text(block)
title = 'Матеріали для майстрів та творчих майстерень | Лавка Художника'
description = 'Скло, кераміка, текстиль, полімерна глина. Матеріали KREUL, Pébéo та Cernit для авторських виробів і малих серій. Напишіть нам про індивідуальні умови.'
preview = '<!doctype html><html lang="uk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' + title + '</title><style>body{margin:0;background:#f7f4ee}main{max-width:1400px;margin:auto}.preview-header{max-width:1400px;margin:auto;padding:18px 44px;border-bottom:1px solid #ddd6c9;display:flex;align-items:center;justify-content:space-between;box-sizing:border-box;font:12px Arial;color:#5e574c}.preview-header strong{font:22px Georgia;color:#78382d}.preview-header a{color:inherit;text-decoration:none}.preview-header small{display:block;font:10px Arial;letter-spacing:.15em;margin-top:4px}@media(max-width:600px){.preview-header{padding:16px 20px}.preview-header>a{max-width:110px;text-align:right}}' + css + '</style></head><body><header class="preview-header"><a href="https://kreul.com.ua/"><strong>Лавка Художника</strong><small>МАТЕРІАЛИ ДЛЯ ТВОРЧОСТІ</small></a><a href="https://kreul.com.ua/">Каталог матеріалів ↗</a></header><main id="main">' + markup + '</main></body></html>'
(args.output / 'index.html').write_text(preview)
(args.output / 'page-meta.json').write_text(json.dumps({'title':'Для майстрів','slug':'dlia-maistriv','seo_title':title,'seo_description':description,'email':args.email,'assets':assets}, ensure_ascii=False, indent=2) + '\n')
print(f'Built preview and WordPress content: {args.output}')

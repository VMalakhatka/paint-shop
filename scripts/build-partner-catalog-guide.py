#!/usr/bin/env python3
"""Build the public Ukrainian partner-export handout. Requires reportlab.

python3 scripts/build-partner-catalog-guide.py --output output/pdf/partner-catalog-guide-uk.pdf
Copy the visually checked PDF to pc-order-import-export/assets for deployment.
Canonical content: docs/WHOLESALE_CUSTOMER_GUIDE_UK.md, partner catalogue section.
"""
from argparse import ArgumentParser
from functools import partial
from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen.canvas import Canvas
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle

ROOT = Path(__file__).resolve().parents[1]
HELP = 'https://kreul.com.ua/my-account/yak-zamovyty/#partner-catalog'
CATALOG = 'https://kreul.com.ua/my-account/?pcoe_catalog=1'
GREEN = colors.HexColor('#235D4B')
INK = colors.HexColor('#22312D')
BLUE = '#125D9A'


def fonts():
    candidates = [
        ('/System/Library/Fonts/Supplemental/Arial.ttf', '/System/Library/Fonts/Supplemental/Arial Bold.ttf'),
        ('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'),
    ]
    for regular, bold in candidates:
        if Path(regular).is_file() and Path(bold).is_file():
            pdfmetrics.registerFont(TTFont('Guide', regular))
            pdfmetrics.registerFont(TTFont('GuideBold', bold))
            pdfmetrics.registerFontFamily('Guide', normal='Guide', bold='GuideBold', italic='Guide', boldItalic='GuideBold')
            return
    raise SystemExit('Install Arial or DejaVu Sans with Cyrillic support.')


def build(output):
    fonts()
    output.parent.mkdir(parents=True, exist_ok=True)
    styles = {
        'body': ParagraphStyle('body', fontName='Guide', fontSize=10.4, leading=14.2, textColor=INK, spaceAfter=7),
        'title': ParagraphStyle('title', fontName='GuideBold', fontSize=25, leading=28.5, textColor=GREEN, spaceAfter=9),
        'heading': ParagraphStyle('heading', fontName='GuideBold', fontSize=12, leading=16, textColor=GREEN, spaceBefore=10, spaceAfter=6),
        'small': ParagraphStyle('small', fontName='Guide', fontSize=9, leading=12, textColor=INK, spaceAfter=4),
        'eyebrow': ParagraphStyle('eyebrow', fontName='GuideBold', fontSize=9.4, leading=13, textColor=GREEN, spaceAfter=10),
    }
    def p(text, kind='body'):
        return Paragraph(text, styles[kind])
    def link(url, label):
        return f'<a href="{url}" color="{BLUE}"><u>{label}</u></a>'
    def heading(number, title, suffix):
        return p(f'{number}. {title}  <font name="Guide" size="9">{link(HELP+suffix, "Докладніше")}</font>', 'heading')

    story = [p('KREUL  /  ПАРТНЕРАМ', 'eyebrow'),
             p('Товари та фото<br/>для вашого магазину', 'title'),
             p('Коротка інструкція: Excel, WooCommerce та Prom.'),
             p('<b>Почніть у своєму оптовому кабінеті:</b> '+link(CATALOG, 'Дані та фото для вашого сайту')+'.<br/>Для розділу та повної довідки потрібен вхід. Якщо доступу немає, зверніться до менеджера.', 'small')]
    story += [heading(1, 'Виберіть товари', '-selection'),
              p('Виберіть категорію, бренд або вставте артикули по одному в рядку. Можна завантажити CSV/XLSX до 5 МБ: артикули в колонці A першого аркуша. Фільтри діють разом; максимум <b>500 товарів</b>.'),
              p('Натисніть <b>«Зберегти та переглянути»</b>. Перевірте ціни, залишки та виключені артикули. На екрані перші 25 товарів, у файлі - вся добірка.')]
    story.append(heading(2, 'Завантажте потрібний формат', '-files'))
    rows = [
        [p('<b>Excel / CSV</b>', 'small'), p('Дані товарів, описи, категорії, фасовка, залишки, посилання на повні фото. Є <b>ваша закупівельна</b> та роздрібна ціна. Файл для власної роботи.', 'small')],
        [p('<b>CSV для WooCommerce<br/>XML для Prom</b>', 'small'), p('Файли імпорту у ваш магазин. <b>Лише роздрібні ціни</b> нашого сайту. Власна націнка тут поки не налаштовується.', 'small')],
        [p('<b>Фото (ZIP)</b>', 'small'), p('До <b>100 фото</b> в архіві. У manifest.csv видно, які фото завантажено, а які пропущено. Для більшої добірки - посилання з таблиці або менші архіви.', 'small')],
    ]
    table = Table(rows, colWidths=[131, A4[0]-84-131], hAlign='LEFT')
    table.setStyle(TableStyle([
        ('BACKGROUND', (0,0), (-1,-1), colors.HexColor('#F0F5F2')),
        ('VALIGN', (0,0), (-1,-1), 'TOP'),
        ('LEFTPADDING', (0,0), (-1,-1), 10), ('RIGHTPADDING', (0,0), (-1,-1), 10),
        ('TOPPADDING', (0,0), (-1,-1), 8), ('BOTTOMPADDING', (0,0), (-1,-1), 6),
        ('LINEBELOW', (0,0), (-1,-2), .5, colors.white),
    ]))
    story.append(table)
    story += [heading(3, 'Підключіть оновлення в Prom', '-prom'),
              p('Після перевірки добірки позначте підтвердження цін і натисніть <b>«Увімкнути посилання Prom»</b>. Скопіюйте персональне XML-посилання в налаштування імпорту Prom.'),
              p('Почніть із кількох товарів: перевірте групи, фото, ціни й наявність. Розклад налаштовується <b>в Prom</b>. Для наступних імпортів вибирайте ціни та наявність, якщо хочете зберегти свої описи.'),
              p('Після зміни добірки посилання призупиняється: перевірте та ввімкніть знову. <b>«Замінити посилання»</b> вимикає стару адресу - оновіть її в Prom. Не публікуйте персональне посилання.', 'small')]
    story += [heading(4, 'Перевірте результат', '-errors'),
              p('Бракує товарів - перевірте артикули й фільтри. Бракує фото - перевірте manifest.csv. Немає роздрібної ціни або експорт не працює - зверніться до менеджера.', 'small'),
              p('Залишки беруться з даних прайсу сайту, а не гарантовано в реальному часі. Експорт <b>не створює замовлень і не резервує товар</b>.', 'small'),
              Spacer(1, 7),
              p(link(HELP, 'Відкрити повну контекстну довідку')+'  |  '+link(CATALOG, 'Відкрити розділ експорту'), 'small')]

    def footer(canvas, doc):
        canvas.setStrokeColor(GREEN)
        canvas.setLineWidth(.6)
        canvas.line(42, 37, A4[0]-42, 37)
        canvas.setFont('Guide', 8)
        canvas.setFillColor(INK)
        canvas.drawString(42, 24, 'kreul.com.ua  ·  Пам’ятка від 11.11.2026  ·  Посилання клікабельні')
        canvas.drawRightString(A4[0]-42, 24, str(doc.page))
    doc = SimpleDocTemplate(str(output), pagesize=A4, rightMargin=42, leftMargin=42,
                            topMargin=36, bottomMargin=49, title='KREUL: товари та фото для вашого магазину',
                            author='KREUL', subject='Коротка інструкція з експорту та підключення Prom')
    doc.build(story, onFirstPage=footer, onLaterPages=footer, canvasmaker=partial(Canvas, invariant=1))

if __name__ == '__main__':
    parser = ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT/'output/pdf/partner-catalog-guide-uk.pdf')
    args = parser.parse_args()
    build(args.output)
    print(args.output)

#!/usr/bin/env python3
"""Build the two-page Ukrainian wholesale promotional handout (no live data)."""
import argparse
from pathlib import Path

from PIL import Image
from reportlab.graphics import renderPDF
from reportlab.graphics.barcode.qr import QrCodeWidget
from reportlab.graphics.shapes import Drawing
from reportlab.lib.colors import HexColor, white
from reportlab.lib.enums import TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas
from reportlab.platypus import Paragraph

ROOT = Path(__file__).resolve().parents[1]
BASE = "https://kreul.com.ua"
HELP = BASE + "/my-account/yak-zamovyty/"
INK, MUTED = HexColor("#202F35"), HexColor("#56656B")
TEAL, PALE = HexColor("#11635D"), HexColor("#EDF5F1")
PAPER = HexColor("#FCFAF6")
BORDER = HexColor("#D8E3DE")
W, H = A4
M, CW = 34, W - 68


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, default=ROOT / "output/pdf/kreul-wholesale-mini-guide-uk.pdf")
    parser.add_argument("--font-regular", default="/System/Library/Fonts/Supplemental/Arial.ttf")
    parser.add_argument("--font-bold", default="/System/Library/Fonts/Supplemental/Arial Bold.ttf")
    args = parser.parse_args()
    pdfmetrics.registerFont(TTFont("Body", args.font_regular))
    pdfmetrics.registerFont(TTFont("Strong", args.font_bold))
    pdfmetrics.registerFontFamily("Body", normal="Body", bold="Strong")
    args.output.parent.mkdir(parents=True, exist_ok=True)
    c = canvas.Canvas(str(args.output), pagesize=A4, pageCompression=1, invariant=1)
    c.setTitle("Замовляйте зручно на kreul.com.ua | Для оптових партнерів")
    c.setAuthor("Лавка Художника")
    c.setSubject("Каталог, швидке замовлення, прайс XLSX, документи та взаєморозрахунки")

    def box(x, top, width, height, fill=white, radius=12, stroke=None):
        c.setFillColor(fill)
        c.setStrokeColor(stroke or fill)
        c.roundRect(x, H-top-height, width, height, radius, fill=1, stroke=bool(stroke))

    def p(text, x, top, width, size=11, color=INK, bold=False, leading=None, maxh=None):
        style = ParagraphStyle("p", fontName="Strong" if bold else "Body", fontSize=size,
                               leading=leading or size*1.33, textColor=color, alignment=TA_LEFT)
        para = Paragraph(text, style)
        _, height = para.wrap(width, H)
        if maxh is not None and height > maxh:
            raise ValueError(f"Text overflow: {text[:65]} ({height} > {maxh})")
        assert top + height < H - 23, f"Page overflow: {text[:65]}"
        para.drawOn(c, x, H-top-height)
        return height

    def link(label, url, x, top, width, size=10.3, color=TEAL):
        return p(f'<link href="{url}" color="{color.hexval()}"><b>{label}</b></link>', x, top, width, size, color)

    def line(x1, top1, x2, top2, color=BORDER, width=1):
        c.setStrokeColor(color)
        c.setLineWidth(width)
        c.line(x1, H-top1, x2, H-top2)

    def crop(filename, source, x, top, width):
        # PDF viewport only: preserve the original screenshot unchanged on disk.
        path = ROOT / "docs/images/wholesale" / filename
        with Image.open(path) as im:
            iw, ih = im.size
        sx, sy, sw, sh = source
        scale = width/sw
        height = sh*scale
        c.saveState()
        clip = c.beginPath()
        clip.rect(x, H-top-height, width, height)
        c.clipPath(clip, stroke=0)
        c.drawImage(str(path), x-sx*scale, H-top-(ih-sy)*scale, width=iw*scale, height=ih*scale)
        c.restoreState()
        return height

    def icon(kind, x, top):
        box(x, top, 32, 32, PALE, 8)
        c.setStrokeColor(TEAL)
        c.setLineWidth(1.5)
        if kind == "doc":
            c.roundRect(x+10, H-top-25, 13, 18, 1.5, stroke=1, fill=0)
            for t in (13, 17, 21):
                line(x+13, top+t, x+20, top+t, TEAL, 1)
        else:
            for dx, h in ((9, 7), (15, 13), (21, 18)):
                c.setFillColor(TEAL)
                c.roundRect(x+dx, H-top-25, 3, h, 1, fill=1, stroke=0)

    def page_base(number, tag):
        c.setFillColor(PAPER)
        c.rect(0, 0, W, H, fill=1, stroke=0)
        p("ЛАВКА ХУДОЖНИКА", M, 25, 240, 10.5, TEAL, True)
        p("KREUL.COM.UA  /  ОПТОВИМ ПАРТНЕРАМ", 311, 27, 250, 8.2, MUTED)
        line(M, 49, W-M, 49)
        line(M, 798, W-M, 798)
        p(tag, M, 808, 440, 8, MUTED)
        p(f"0{number} / 02", 522, 807, 50, 8.5, TEAL, True)

    page_base(1, "Лавка Художника  •  Зручніше замовляти. Легше планувати.")
    p("Ваше замовлення.<br/>Без зайвих кроків.", M, 65, 490, 31, INK, True, leading=34)
    p("Ваші ціни, залишки по складах і два зручні способи вибрати товар.<br/>Працюйте на kreul.com.ua у власному темпі.",
      M, 144, CW, 11.5, MUTED, maxh=34)

    box(M, 192, CW, 287, white, stroke=BORDER)
    p("01", 51, 207, 34, 11, TEAL, True)
    p("Обирайте очима", 88, 204, 400, 20, INK, True)
    crop("catalog-product-cards.jpg", (173, 470, 503, 379), 49, 241, 299)
    p("КАТАЛОГ ІЗ ФОТО", 365, 250, 173, 9, TEAL, True)
    p("Відкрийте картку: фото, опис і характеристики допоможуть обрати потрібне.", 365, 272, 172, 11, maxh=75)
    p("Одразу видно <b>вашу ціну</b> та <b>залишки на складах</b>. Вкажіть кількість і додайте у кошик.", 365, 350, 173, 11, maxh=85)
    link("Відкрити каталог →", BASE + "/", 365, 444, 177)

    p("02", M, 499, 35, 11, TEAL, True)
    p("Збирайте замовлення списком", 71, 495, 480, 20, INK, True)
    p("Коли знаєте, що потрібно: знаходьте товари за назвою, артикулом<br/>або штрихкодом і задавайте кількості прямо в таблиці.", M, 528, CW, 11, MUTED)
    box(M, 572, CW, 127, white, stroke=BORDER)
    p("Назва й артикул", 47, 583, 210, 8.8, TEAL, True)
    p("Ціна", 362, 583, 48, 8.8, TEAL, True)
    p("Наявність", 453, 583, 76, 8.8, TEAL, True)
    crop("quick-order-table.jpg", (38, 321, 1355, 250), 43, 603, CW-18)
    link("Перейти до товарів списком →", BASE + "/shvydke-zamovlennia/", M, 710, CW)
    p("Фрагменти інтерфейсу. Ціни та залишки на фото наведені для прикладу.", M, 731, CW, 8, MUTED)
    box(M, 752, CW, 33, PALE, 8)
    p("<b>Склад обираєте ви або система.</b> Перед оформленням перевірте розподіл у кошику.", 46, 762, CW-24, 9.5, TEAL)
    c.showPage()

    page_base(2, "Коротка пам’ятка  •  Повна інструкція завжди доступна у вашому кабінеті")
    p("Усе під контролем.<br/>В одному кабінеті.", M, 65, 510, 29, INK, True, leading=32)
    p("Від вибору товару до документів і взаєморозрахунків.", M, 139, CW, 11.5, MUTED)

    col = (CW-14)/2
    for x, kind, title, desc, label, url in [
        (M, "doc", "Ваші документи", "Рахунки, накладні та платежі з нашої бази ФОЛІО. Переглядайте склад документа й повторюйте потрібні товари у новому замовленні.", "Документи ФОЛІО →", "/my-account/folio-documents/"),
        (M+col+14, "balance", "Взаєморозрахунки", "Перевіряйте оплати, борг або передплату. Формуйте деталізацію за період, завантажуйте XLSX чи друкуйте звіт.", "Баланс із клієнтом →", "/my-account/folio-balance/"),
    ]:
        box(x, 172, col, 172, white, stroke=BORDER)
        icon(kind, x+15, 187)
        p(title, x+57, 194, col-67, 14.3, INK, True)
        p(desc, x+15, 231, col-30, 10.7, MUTED, maxh=76)
        link(label, BASE+url, x+15, 318, col-30, 10)

    p("Звикли замовляти в Excel?", M, 362, CW, 20, INK, True)
    labels = [("1", "Скачайте прайс", "Ваші ціни й залишки"),
              ("2", "Заповніть «Замовити»", "Лише потрібні кількості"),
              ("3", "Завантажте на сайт", "У чернетку або кошик")]
    stepw = (CW-20)/3
    for i, (num, title, subtitle) in enumerate(labels):
        x = M + i*(stepw+10)
        box(x, 397, stepw, 71, PALE, 9)
        p(num, x+11, 405, 20, 12, TEAL, True)
        p(title, x+11, 424, stepw-20, 10.5, TEAL, True)
        p(subtitle, x+11, 444, stepw-20, 9.1, MUTED)
    p("Потім перевірте кошик і оформіть замовлення. Чернетку можна завершити пізніше.<br/>При імпорті сайт перевірить ціни й наявність заново.", M, 478, CW, 10, MUTED)

    box(M, 520, CW, 133, TEAL)
    p("Чому замовляти на сайті вигідно?", 50, 536, CW-32, 18, white, True)
    p("Після успішної обробки документи автоматично потрапляють у ФОЛІО, а наявний товар резервується під ваше замовлення. Підсумок видно в кабінеті.", 50, 566, CW-32, 11, white, maxh=47)
    p("Менше ручних узгоджень і витрат на обробку, швидше комплектування та відвантаження. Це допомагає нам пропонувати <b>партнерські ціни навіть для невеликих замовлень.</b>", 50, 609, CW-32, 10.5, white, maxh=42)
    p("Перед підтвердженням перевірте замовлення й поставте галочку згоди з Правилами та умовами. Кошик і чернетка не резервують товар. При оплаті карткою обробка чекає підтвердження оплати. Товари з різних складів можуть мати окремі документи й відправлення.", M, 662, CW, 8.8, MUTED, maxh=38)

    qr = QrCodeWidget(HELP)
    qr.barFillColor = INK
    qx, qy, qx2, qy2 = qr.getBounds()
    qs = 77
    drawing = Drawing(qs, qs, transform=[qs/(qx2-qx), 0, 0, qs/(qy2-qy), 0, 0])
    drawing.add(qr)
    renderPDF.draw(drawing, c, W-M-qs, H-708-qs)
    c.linkURL(HELP, (W-M-qs, H-785, W-M, H-708), relative=0)
    link("Почнімо з вашого кабінету →", BASE + "/my-account/", M, 709, 420, 13)
    p("Зареєструйтеся та зверніться до нас: підключимо оптовий доступ<br/>і зв’яжемо профіль з ФОЛІО для документів та балансу.", M, 731, 421, 9.5, MUTED)
    link("Повна інструкція «Як замовляти» →", HELP, M, 756, 420, 10)
    p("Пишіть і телефонуйте мені особисто: відповім на запитання<br/>й врахую ваші побажання, щоб працювати було ще зручніше.", M, 774, 422, 8.8, TEAL, leading=10)
    c.save()
    print(args.output)


if __name__ == "__main__":
    main()

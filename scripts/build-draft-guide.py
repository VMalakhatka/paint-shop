"""Build the customer PDF from verified synthetic UI captures, not customer data."""
from pathlib import Path
import os
import shutil
from reportlab.pdfgen import canvas
from reportlab.lib.colors import HexColor, white
from reportlab.lib.styles import ParagraphStyle
from reportlab.platypus import Paragraph
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.lib.utils import ImageReader

ROOT = Path(__file__).resolve().parents[1]
ASSETS = ROOT / "wp-content/plugins/pc-order-import-export/assets/draft-guide"
OUT = ROOT / "output/pdf/drafts-and-preorders-uk.pdf"
FONT = Path(os.environ.get("GUIDE_FONT_DIR", "/System/Library/Fonts/Supplemental"))
pdfmetrics.registerFont(TTFont("Guide", str(FONT / "Arial.ttf")))
pdfmetrics.registerFont(TTFont("GuideBold", str(FONT / "Arial Bold.ttf")))
pdfmetrics.registerFontFamily("Guide", normal="Guide", bold="GuideBold")
OUT.parent.mkdir(parents=True, exist_ok=True)
c = canvas.Canvas(str(OUT), pagesize=(595.28, 841.89), pageCompression=1)
c.setTitle("Чернетки та передзамовлення | Лавка Художника")
c.setAuthor("Лавка Художника")
C = HexColor("#A6533C")
INK = HexColor("#27312F")
MUTED = HexColor("#62706B")
GREEN = HexColor("#28634C")
LEFT, WIDTH, H = 42, 511, 841.89


def text(value, top, size=12, bold=False, color=INK, width=WIDTH, x=LEFT):
    style = ParagraphStyle("t", fontName="GuideBold" if bold else "Guide",
                           fontSize=size, leading=size * 1.42, textColor=color)
    p = Paragraph(value, style)
    _, height = p.wrap(width, H)
    assert top + height < 785, (value[:60], top + height)
    p.drawOn(c, x, H - top - height)
    return top + height


def start(number, title, subtitle):
    c.setFillColor(C)
    c.rect(LEFT, H - 33, 32, 4, fill=1, stroke=0)
    text("ЛАВКА ХУДОЖНИКА", 23, 9, True, x=84)
    text("ІНСТРУКЦІЯ ДЛЯ КЛІЄНТА", 23, 8, color=MUTED, x=387, width=170)
    c.bookmarkPage("page" + str(number))
    c.addOutlineEntry(title, "page" + str(number), 0)
    top = text(title, 60, 28, True)
    top = text(subtitle, top + 9, 12, color=MUTED)
    c.setStrokeColor(HexColor("#DDE3DF"))
    c.line(LEFT, 47, LEFT + WIDTH, 47)
    c.setFont("Guide", 8)
    c.setFillColor(MUTED)
    c.drawString(LEFT, 30, "kreul.com.ua  |  Інструкція від 16.10.2026")
    c.drawRightString(LEFT + WIDTH, 30, str(number) + " / 5")
    c.linkURL("https://kreul.com.ua/", (LEFT, 25, 120, 40), relative=0)
    return top + 24


def step(number, title, body, top):
    c.setFillColor(C)
    c.circle(LEFT + 11, H - top - 12, 11, fill=1, stroke=0)
    c.setFillColor(white)
    c.setFont("GuideBold", 11)
    c.drawCentredString(LEFT + 11, H - top - 16, str(number))
    end = text(title, top, 13, True, x=LEFT + 35, width=WIDTH - 35)
    return text(body, end + 4, 11.5, x=LEFT + 35, width=WIDTH - 35) + 16


def picture(name, top, maximum, caption):
    reader = ImageReader(str(ASSETS / name))
    iw, ih = reader.getSize()
    ratio = min(WIDTH / iw, maximum / ih)
    w, h = iw * ratio, ih * ratio
    c.setStrokeColor(HexColor("#DDE3DF"))
    x = LEFT + (WIDTH - w) / 2
    c.drawImage(reader, x, H - top - h, width=w, height=h, mask="auto")
    c.rect(x, H - top - h, w, h, fill=0, stroke=1)
    return text(caption, top + h + 7, 9, color=MUTED) + 18


def note(title, body, top):
    c.setStrokeColor(C)
    c.setLineWidth(3)
    end = text(title, top, 12, True, color=C, x=LEFT + 14, width=WIDTH - 14)
    end = text(body, end + 4, 11, x=LEFT + 14, width=WIDTH - 14)
    c.line(LEFT + 2, H - top, LEFT + 2, H - end)
    c.setLineWidth(1)
    return end + 20


y = start(1, "Чернетки та<br/>передзамовлення", "Зберіть потрібні товари зараз. Вирішіть, як замовити їх, пізніше.")
y = step(1, "Увійдіть у свій кабінет", "Режим чернетки доступний зареєстрованим клієнтам після входу. Потім відкрийте каталог.", y)
y = step(2, "Увімкніть «Чернетка»", "Над товарами перемкніть «Покупка» на «Чернетка». Виберіть готовий список або відкрийте «Нова чернетка».", y)
y = picture("collect.png", y, 210, "Приклад: створення списку «Фарби для майстерні» на сайті.")
y = step(3, "Дайте списку зрозумілу назву", "Введіть назву та натисніть «Створити чернетку». Перевірте, що саме вона вибрана у полі «Вибрана чернетка».", y)
note("Товари одразу потрапляють у чернетку", "Проміжний кошик не потрібен. Список зберігається, але товар ще не резервується, замовлення й документ ФОЛІО не створюються.", y)
c.showPage()

y = start(2, "Додайте й перевірте товари", "Нульовий залишок не заважає скласти список потрібного.")
y = step(1, "Вкажіть кількість і натисніть «У чернетку»", "Можна вказати потрібну додатну цілу кількість без обмеження складським залишком. Для фарби з варіантами спочатку виберіть колір, об'єм та інші параметри.", y)
y = step(2, "Відкрийте чернетку", "Натисніть «Відкрити чернетку» біля її вибору. Пізніше її можна знайти в «Кабінет → Мої замовлення».", y)
y = picture("edit.png", y, 245, "Навчальний список. Усі назви й кількості наведені для прикладу.")
y = step(3, "Виправте кількості та збережіть", "Розгорніть «Змінити кількості в чернетці». Змініть числа й натисніть «Зберегти кількості». <b>0 видаляє рядок.</b>", y)
note("Два прості правила", "Повторне додавання того самого варіанта збільшує його кількість. Ціни в чернетці попередні: під час перенесення в кошик вони перевіряються заново.", y)
c.showPage()

y = start(3, "Купити те, що є в наявності", "Цей шлях підходить, коли частину списку хочете замовити зараз.")
y = step(1, "Відкрийте «Обробка чернетки»", "На сторінці чернетки натисніть «Завантажити доступну кількість у кошик». Спочатку відкриється перегляд, без запису документа.", y)
y = text("ПРИКЛАД РОЗПОДІЛУ ОДНОГО ТОВАРУ", y + 2, 10, True, color=MUTED) + 14
for i, (n, label, color) in enumerate([("12", "Потрібно", INK), ("7", "У кошик", GREEN), ("5", "Залишок у ФОЛІО", C)]):
    x = LEFT + i * 173
    c.setFillColor(HexColor("#F1F4F2"))
    c.rect(x, H - y - 85, 162, 85, fill=1, stroke=0)
    text(n, y + 7, 28, True, color=color, x=x + 12, width=140)
    text(label, y + 53, 10, x=x + 12, width=140)
y += 106
y = step(2, "Перевірте три кількості", "Скільки запитано, скільки піде в кошик і скільки залишиться для ФОЛІО. Підтвердьте лише після перевірки.", y)
y = note("Увага: поточний кошик буде замінено", "Перед підтвердженням збережіть старий кошик окремою чернеткою або експортуйте його, якщо він потрібен. Доступна частина нового списку займе його місце.", y)
y = step(3, "Оформіть замовлення з кошика", "Перевірте товари, ціни, склади й доставку. Саме перенесення товару в кошик ще не оформлює замовлення.", y)
note("Що буде з недоступною частиною?", "Вона залишиться у чернетці та після підтвердження буде записана в необліковий документ ФОЛІО з позначкою «нет на складе». Якщо доступний весь список, такий документ не потрібен.", y)
c.showPage()

y = start(4, "Весь список у ФОЛІО", "Кошик не змінюється. Оберіть, для чого передаєте список.")
y = step(1, "Оберіть «Зберегти як»", "<b>Звичайна чернетка</b> - список для узгодження.<br/><b>Рахунок передзамовлення</b> - список для передзамовлення з позначкою «ПРД» у ФОЛІО.", y)
y = picture("folio.png", y, 320, "Навчальний приклад: у полі «Зберегти як» вибрано рахунок передзамовлення.")
y = step(2, "Натисніть «Передати всю чернетку…»", "Кнопка «Передати всю чернетку в необліковий документ ФОЛІО» відкриває попередній перегляд. Це ще не підтвердження запису.", y)
note("Передзамовлення - не складський резерв", "Воно враховується в резерві передзамовлень протягом шести місяців, але не резервує й не списує поточний залишок. Оплату та строки постачання узгодьте з менеджером.", y)
c.showPage()

y = start(5, "Перевірте й підтвердьте", "Останній крок перед записом документа у ФОЛІО.")
y = picture("preview.png", y, 265, "Навчальний перегляд передзамовлення. Артикули DEMO - приклади, не реальні товари.")
y = step(1, "Звірте тип документа й усі рядки", "Перевірте, що вказано саме «Рахунок передзамовлення» або «Звичайна чернетка», та потрібні кількості. Для всього списку колонка «У кошик» дорівнює нулю: це не помилка.", y)
y = step(2, "Підтвердьте або скасуйте", "Натисніть «Підтвердити необліковий документ ФОЛІО». Лише тепер документ записується. «Скасувати» залишає його нествореним. Після успіху перевірте повідомлення та зв'язок із документом.", y)
y = note("Помилка або невідомий результат?", "Не створюйте копію навмання. Надішліть менеджеру номер чернетки та повідомлення. Спочатку потрібно перевірити, чи документ уже створено. Якщо склад ФОЛІО не налаштований, його має налаштувати менеджер.", y)
text("Підказки завжди поруч", y, 13, True)
text("Відкрийте «Як працювати з чернеткою?» біля вибору списку або в блоці «Обробка чернетки». Там є ці ж кроки та посилання на PDF. Переданий у ФОЛІО список тут уже не редагується.", y + 23, 11)
c.save()
shutil.copyfile(OUT, ASSETS / OUT.name)
print(OUT)

# 🛒 Paint Shop (WooCommerce)

E-commerce проект на базе **WordPress + WooCommerce**, кастомизированный под задачи магазина красок.

## 📂 Структура проекта
app/public/           # корень WordPress
├─ wp-content/
│   ├─ themes/
│   │   └─ my-theme/           # кастомная тема (с оверрайдами WooCommerce)
│   ├─ plugins/
│   │   └─ my-custom-plugin/   # кастомные плагины
│   ├─ uploads/                # медиафайлы (не в Git)
│   └─ mu-plugins/             # must-use плагины (если есть)
├─ .gitignore
├─ wp-cli.yml
└─ README.md

## 🚀 Как развернуть проект

1. Установить WordPress и WooCommerce (через WP-CLI):
   ```bash
   wp core download --locale=ru_RU
   wp core config --dbname=paint --dbuser=root --dbpass=root --dbhost=localhost
   wp core install --url=http://localhost --title="Paint Shop" --admin_user=admin --admin_password=admin --admin_email=admin@example.com
   wp plugin install woocommerce --activate
	2.	Подтянуть кастомные файлы:
   git clone git@github.com:VMalakhatka/paint-shop.git .
   	3.	Активировать тему:
    wp theme activate my-theme
    	4.	Активировать кастомные плагины:

        wp plugin activate my-custom-plugin
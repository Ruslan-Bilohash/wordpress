# 🚀 Ruslan AI Design Generator

**AI-генератор дизайну для WordPress** — створює та редагує блоки, шаблони сторінок і цілі теми за допомогою OpenAI прямо в Gutenberg.

Версія: **4.0.1**  
Автор: Grok + Руслан  
Ліцензія: GPL-2.0+

Додай у файл wp-config.php:
define('OPENAI_API_KEY', 'sk-XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX');

---

## ✨ Основні можливості

- **Генерація окремих блоків** — форми, герої, секції, картки тощо з Tailwind CSS
- **Повні шаблони сторінок** з демо-даними (Landing, About, Contact, Shop тощо)
- **Створення повноцінних Block Theme** — автоматично створює папку в `/wp-content/themes/`
- **Редагування існуючих тем** — обираєш тему → файл → пишеш промпт → AI оновлює код
- **Автоматичні бекапи** перед кожним редагуванням
- **100% адаптивність** (mobile-first) + підтримка темної теми
- **Безпека на вищому рівні** — nonces, capability checks, wp_kses, унікальний клас
- Працює з **gpt-4o-mini** (швидко та дешево)

---

## 📸 Як це виглядає

- Додаєш блок у Gutenberg
- Обираєш режим: Один блок / Повний шаблон / Нова тема / Редагувати тему
- Пишеш промпт українською
- AI генерує або редагує код у реальному часі

---

## Вимоги

- WordPress 6.4+
- PHP 8.2+
- OpenAI API Key
- Активований Gutenberg (Block Editor)

---

## 🚀 Встановлення

### Спосіб 1: Ручне встановлення (рекомендовано)

1. Завантаж репозиторій як ZIP
2. Розпакуй у папку `/wp-content/plugins/ai-design-generator/`
3. Активуй плагін у WordPress → Плагіни
4. Перейди в **Налаштування → Ruslan AI** і введи OpenAI API Key

### Спосіб 2: Через GitHub

```bash
cd wp-content/plugins/
git clone https://github.com/твій-нік/ai-design-generator.git

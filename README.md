# 🚀 xForge AI + GROK XAI AI Design Generator

**Two powerful AI Design plugins for WordPress** in one repository.

---

## 📌 Plugin Comparison

| Plugin                          | AI Provider          | Version   | Key Features                                   | Status      | Recommended |
|---------------------------------|----------------------|-----------|------------------------------------------------|-------------|-------------|
| **xForge AI**      GROK XAI              | Grok / xAI (OpenAI)  | Latest    | Blocks, Full Pages, Full Themes, **Edit any theme** | **Main**    | ⭐ **YES**   |
| **AI Design Generator**| ChatGPT              | Legacy    | Basic AI generation                            | Legacy      | No          |

---

## 1. xForge AI (Main & Recommended Plugin)

**The most advanced AI Design Generator for WordPress** — powered by **Grok / xAI (OpenAI)**.

### Key Features
- Generate single blocks with Tailwind CSS
- Create full page templates with realistic demo content
- Build complete Block Themes automatically
- **Edit any existing theme** in real time (best feature)
- Automatic backups before every edit
- 100% mobile-first + dark mode support
- Modern clean code (no deprecated warnings)
- Very fast and cost-effective

**Current Version:** 4.0.2

---

## 2. GROK XAI AI Design Generator (Legacy)

Older version of the AI Design Generator that uses **ChatGPT**.

This is the previous plugin.  
It is no longer actively developed and may cause class name conflicts.

**Recommendation:** Deactivate **GROK XAI AI Design Generator** and use **xForge AI** instead.

---

## 🚀 Installation

### xForge AI (Main Plugin)

1. Upload the folder to `/wp-content/plugins/ai-design-generator/`
2. Activate **"xForge AI"** in WordPress → Plugins
3. Go to **Settings → xForge AI** and enter your OpenAI API Key

**Most secure way:**
```php
// wp-config.php
define('OPENAI_API_KEY', 'sk-XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX');

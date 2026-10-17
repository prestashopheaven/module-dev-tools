---
module: {{MODULE_NAME}}
version: {{VERSION}}
language: en
title: User manual
toc_title: Contents
summary: {{DESCRIPTION}}
---

<!--
  User manual of {{DISPLAY_NAME}}, written for merchants (not for developers).
  - Keep the same sections (## headings) and screenshots in every language.
  - Screenshots: ![Caption](screenshot:<id>) with an id from docs/docs.json; own images: ![Caption](images/<file>.png).
  - Callouts: "> [!TIP]", "> [!NOTE]", "> [!WARNING]" on the first line of a quote.
  - Filled in at build time: {{version}}, {{display_name}}, {{module_name}}, {{ps_min}}, {{ps_tested}}, {{php_min}}, {{author}}.
  - Replace every TODO comment with real text; the build refuses while one is left.
-->

## Introduction

{{DESCRIPTION}}

<!-- TODO: two or three paragraphs: the problem the module solves, for which shops, and what the merchant and the customers see. -->

## Features

<!-- TODO: the main features as a list (the same as "features" in marketing.json), one line each, benefit first. -->

## Requirements

- PrestaShop {{ps_min}} or newer (tested up to {{ps_tested}})
- PHP {{php_min}} or newer
- Works in every shop of a multistore installation

<!-- TODO: add real requirements only (an external account, an API key). Prefer none. -->

## Installation

1. Download the file **{{module_name}}.zip** from your PrestaShop Addons account.
2. In your back office, go to **Modules › Module Manager** and click **Upload a module**.
3. Drop the zip file in the window or select it. PrestaShop installs the module.
4. Click **Configure** to open the settings of {{display_name}}.

## Configuration

<!-- TODO: every setting of the configuration page: what it does, the default value, a tip for a good value. Use a table or a list. -->

![The configuration page of {{display_name}}](screenshot:configure)

## In your shop

<!-- TODO: what customers see in the front office and where, on a computer and on a phone. -->

![{{display_name}} on the home page](screenshot:front)

![{{display_name}} in the Classic theme](screenshot:front-classic)

![{{display_name}} on a phone](screenshot:front-mobile)

## Multistore

<!-- TODO: how the settings work in a multistore installation (all shops, a group, one shop). -->

## Uninstalling

Go to **Modules › Module Manager**, find {{display_name}} and choose **Uninstall** in the menu of the module. The module removes its settings and data from your shop.

## Troubleshooting

<!-- TODO: the most likely questions and problems with their solution (e.g. "I don't see the module in my shop": clear the cache in Advanced Parameters › Performance). -->

## Support

<!-- TODO: how merchants get help (Addons contact form of the module), and what to include in a question. -->

# Image for `ps-module docs`: screenshots of the test shops, PDF manuals and PNG previews.
# Built on demand by src/docs.php and tagged prestashopheaven/docs-tools:<hash of this file>,
# so a change here gives a new image and an unchanged file reuses the cached one.
#
# - Playwright base image: Node + Chromium with all system libraries (Ubuntu 24.04 "noble").
# - Noto fonts for every script in resources/languages.json: Latin, Cyrillic, Greek (core),
#   Arabic + Thai (core), Chinese/Japanese/Korean (cjk), emoji (color-emoji).
# - poppler-utils: pdftoppm renders PDF pages to PNG for `ps-module docs preview`.
# - playwright-core: the browser automation library without a browser download
#   (the browsers are already in the image, same version).
FROM mcr.microsoft.com/playwright:v1.52.0-noble

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        fonts-noto-core fonts-noto-ui-core fonts-noto-cjk fonts-noto-color-emoji fonts-noto-mono \
        poppler-utils \
    && fc-cache -f \
    && rm -rf /var/lib/apt/lists/*

RUN mkdir -p /opt/docs-tools \
    && cd /opt/docs-tools \
    && npm init -y > /dev/null \
    && npm install --no-audit --no-fund --omit=dev playwright-core@1.52.0

ENV NODE_PATH=/opt/docs-tools/node_modules \
    PLAYWRIGHT_BROWSERS_PATH=/ms-playwright

WORKDIR /work

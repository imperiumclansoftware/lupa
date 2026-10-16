# Rendu de la vidéo promotionnelle : Chromium (Playwright) image par image, puis ffmpeg
FROM mcr.microsoft.com/playwright:v1.59.1-noble
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg && rm -rf /var/lib/apt/lists/*
WORKDIR /opt/render
RUN npm init -y >/dev/null && npm install --no-audit --no-fund playwright@1.59.1
ENV NODE_PATH=/opt/render/node_modules
WORKDIR /work

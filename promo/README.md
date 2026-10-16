# Vidéo de présentation

Vidéo de 60 s (1920×1080, muette) : `video.html` est une composition HTML dont chaque image est
calculée à partir du temps (`window.seek(t)`), rendue par Chromium puis assemblée par ffmpeg.

```bash
docker build -t lupa-promo promo
# Captures réelles des interfaces (environnement de dev démarré, compte existant)
docker run --rm --network sas_edge -e USER=… -e PASS=… -v "$PWD/promo:/work" lupa-promo node capture.cjs
# Images de contrôle, puis vidéo complète : promo/out/lupa.mp4 et lupa-poster.png
docker run --rm -v "$PWD/promo:/work" lupa-promo node render.cjs stills 10 20 30
docker run --rm -v "$PWD/promo:/work" lupa-promo node render.cjs video
```

Polices : Manrope, Cinzel et JetBrains Mono, sous licence SIL Open Font License (`assets/fonts/LICENSE-*.txt`).

# Génération des documents PDF (conditions, confidentialité, politiques de sécurité) : make docs
FROM python:3.13-slim
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpango-1.0-0 libpangoft2-1.0-0 libharfbuzz-subset0 fonts-dejavu-core fonts-noto-core \
 && rm -rf /var/lib/apt/lists/* \
 && pip install --no-cache-dir weasyprint==66.0
WORKDIR /docs
ENTRYPOINT ["weasyprint"]

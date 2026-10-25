FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && groupadd --gid 10001 cleaner \
    && useradd --uid 10001 --gid cleaner --no-create-home cleaner

WORKDIR /app
COPY validator.php /app/validator.php
RUN php -l /app/validator.php \
    && mkdir -p /app/m3u /app/output /var/log \
    && chown cleaner:cleaner /app/m3u /app/output /var/log

ENV M3U_DIRECTORY=/app/m3u \
    OUTPUT_FILE=/app/output/cleaned_playlist.m3u \
    LOG_FILE=/var/log/validator.log \
    PROBE_TIMEOUT_SECONDS=15
USER 10001:10001
ENTRYPOINT ["php", "/app/validator.php"]

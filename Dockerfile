# Image chạy StockFlow bằng PHP built-in server (phù hợp demo/phát triển; production nên dùng php-fpm + nginx).
FROM php:8.3-cli
RUN docker-php-ext-install mysqli
WORKDIR /app
COPY . /app
RUN chmod +x bin/stockflow docker/entrypoint.sh
EXPOSE 8080
ENTRYPOINT ["docker/entrypoint.sh"]

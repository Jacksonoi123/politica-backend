FROM php:8.2-cli

# Instala dependências do sistema para compilar PDO e SQLite
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    gcc \
    make \
    autoconf \
    pkg-config \
    && docker-php-ext-install pdo pdo_sqlite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY . /app
WORKDIR /app

EXPOSE 10000

CMD ["php", "-S", "0.0.0.0:10000"]

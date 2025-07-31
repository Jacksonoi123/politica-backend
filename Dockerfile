FROM php:8.2-cli-bullseye

# Atualiza os pacotes do sistema para corrigir vulnerabilidades
RUN apt-get update && apt-get upgrade -y && apt-get clean

# Instala extensões necessárias (PDO + SQLite)
RUN docker-php-ext-install pdo pdo_sqlite

# Copia os arquivos do projeto para /app
COPY . /app
WORKDIR /app

# Expor a porta que o Render vai usar
EXPOSE 10000

# Rodar o servidor embutido do PHP com o server.php
CMD php -S 0.0.0.0:10000

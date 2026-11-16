# Sistema de Gestão de Estoque de TI — API (Backend)

API RESTful desenvolvida em **PHP + Laravel 12**, **Laravel Sanctum** e **MySQL** para o controle de inventário, reservas de balcão, notas fiscais e livro-razão imutável de movimentações do setor de Tecnologia da Informação (TI).

## Tecnologias
- **PHP 8.2+ / Laravel 12**
- **Laravel Sanctum** (Autenticação via Bearer Token)
- **MySQL / MariaDB** (Integridade referencial e triggers de imutabilidade no livro-razão)

## Como executar localmente

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve --port=8000
```

## Credenciais de Demonstração (Seeder)
Senha padrão para todas as contas: `password`
- **Admin TI**: `admin@estoque.local`
- **Gestora de TI**: `gestor.ti@estoque.local`
- **Operador de Suporte TI**: `operador.ti@estoque.local`

## Testes Automatizados
```bash
php artisan test
```

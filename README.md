# Portal Varela Rally – Oficina e Equipe

Sistema web de gestão da oficina e da equipe Varela Rally, em produção em https://varelarally.com.br/.

Este repositório contém a **versão v14** (17/11/2026) do portal.

## Tecnologia
- PHP + PDO/MySQL (banco na Locaweb, dbaas)
- Bootstrap
- Hospedagem: Locaweb (Hospedagem GO Linux)

## Áreas / tipos de login
- `admin/` – administração (ordens de serviço, clientes, corridas, financeiro, folha, reembolsos)
- `mecanico/` – mecânicos
- `cliente/` – clientes (carros, OS, fotos, corridas)
- `navegador/` – navegadores
- `estoque/` – estoque e mapa de armazenamento
- `equipe/` – equipe
- `includes/` – funções compartilhadas (autenticação, cabeçalho, financeiro, etc.)
- `migrate9.php` … `migrate13.php` – scripts de migração do banco

## Configuração
1. Copie `config.example.php` para `config.php`.
2. Preencha host, nome do banco, usuário e senha.
3. Crie a pasta `uploads/` com permissão de escrita (o `uploads/.htaccess` já está no repositório).

O `config.php`, a pasta `uploads/` (comprovantes e fotos) e backups de banco **não** vão para o GitHub (veja `.gitignore`).

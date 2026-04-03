# Order Pipeline — Event-Driven Architecture com RabbitMQ

Projeto de estudo para aprofundar conhecimentos em **arquitetura orientada a eventos** e **sistemas de mensageria**, evoluindo do modelo tradicional de Events/Listeners/Jobs do Laravel para uma pipeline resiliente com RabbitMQ.

Ao criar um pedido, ele é classificado automaticamente por IA (Gemini via Laravel AI) como **seguro**, **suspeito** ou **fraude**, e roteado para as filas corretas via Topic Exchange do RabbitMQ.

---

## Conceitos aplicados

- **Transactional Outbox Pattern** — pedido e evento salvos atomicamente no banco. Relay publica no RabbitMQ de forma assíncrona, sem risco de perda
- **Topic Exchange** — o RabbitMQ decide o roteamento por routing key. `order.classified.fraud` cai em `orders.fraud` + `orders.audit` sem o código saber
- **Dead Letter Exchange (DLX)** — falha no consumer → nack → fila de espera com TTL → reprocessamento automático → após 3 tentativas → Dead Letter Queue
- **Idempotência** — consumidores verificam o estado antes de processar, tornando reentregas seguras (at-least-once delivery)
- **Clean Architecture** — Controller valida e delega. Service orquestra. Repository abstrai persistência. Worker cuida apenas de infraestrutura

---

## Fluxo

```
POST /api/orders
      │
      └── DB::transaction {
              INSERT orders
              INSERT outbox_events   ← atomicidade garantida
          }

outbox:relay (a cada 5s)
      └── publica order.created no RabbitMQ
              │
              RabbitMQ Topic Exchange
              │
              └── orders.created → ClassificationWorker
                        │
                        ├── Gemini AI classifica
                        │
                        └── DB::transaction {
                                UPDATE order (risk_level, status)
                                INSERT outbox_events (order.classified.*)
                            }

outbox:relay (2ª execução)
      └── publica order.classified.* no RabbitMQ
              │
              ├── order.classified.safe       → orders.classified.safe
              ├── order.classified.suspicious → orders.classified.suspicious + orders.audit
              └── order.classified.fraud      → orders.classified.fraud      + orders.audit
```

### Retry com DLX

```
Worker falha → nack → orders.created.retry (TTL 5s)
                              ↓ expira
                       orders.created (retry 1)
                              ↓ falha de novo
                       ... até 3 tentativas
                              ↓
                       orders.dead-letter
```

---

## Stack

| Tecnologia | Uso |
|---|---|
| Laravel 13 + PHP 8.4 | Framework principal |
| RabbitMQ | Message broker com Topic Exchange, DLX e TTL |
| Laravel AI + Gemini | Classificação de pedidos por IA |
| MySQL | Persistência + tabela outbox |
| Docker Compose | Orquestração de todos os serviços |
| PHPUnit | 58 testes cobrindo domínio, infra e integração |

---

## Estrutura relevante

```
app/
├── Console/Commands/
│   ├── OutboxRelay.php              # Publica outbox → RabbitMQ
│   └── Workers/
│       ├── ClassificationWorker.php # Consome orders.created
│       └── AuditWorker.php          # Consome orders.audit
├── EventBus/
│   ├── EventBusInterface.php        # Contrato de publicação
│   └── OutboxEventBus.php           # Implementação via banco
├── Events/
│   ├── DomainEvent.php              # Interface base
│   ├── OrderCreated.php
│   └── OrderClassified.php
├── Repositories/
│   ├── OrderRepositoryInterface.php
│   └── EloquentOrderRepository.php
└── Services/
    ├── OrderService.php             # Cria pedido + publica evento
    ├── ClassificationService.php    # Classifica + emite evento
    └── RabbitMQService.php          # Conexão, topologia, publish/consume
```

---

## Como rodar

### Pré-requisitos
- Docker e Docker Compose
- Chave de API do Gemini (gratuita em [aistudio.google.com](https://aistudio.google.com))

### Setup

```bash
git clone <repo>
cd orders

cp .env.example .env
# Adicione sua GEMINI_API_KEY no .env

docker compose up -d --build
docker compose exec app php artisan migrate
```

### Endpoints

```bash
# Criar pedido
POST http://localhost:8080/api/orders
{
  "description": "Notebook Dell XPS 15",
  "amount": 8500
}

# Listar pedidos
GET http://localhost:8080/api/orders

# Detalhar pedido
GET http://localhost:8080/api/orders/{id}
```

### Testando o fluxo manualmente

```bash
# 1. Criar pedido
docker compose exec app php artisan orders:create --description="Teste" --amount=500

# 2. Publicar no RabbitMQ (simula o scheduler)
docker compose exec app php artisan outbox:relay

# 3. Classificar com IA
docker compose exec app php artisan worker:classification

# 4. Publicar eventos de classificação
docker compose exec app php artisan outbox:relay
```

Painel do RabbitMQ: [http://localhost:15672](http://localhost:15672) — usuário `guest`, senha `guest`

### Testes

```bash
docker compose exec app php artisan test
```

---

## Serviços Docker

| Container | Função |
|---|---|
| `orders-app` | PHP-FPM (API) |
| `orders-nginx` | Servidor HTTP na porta 8080 |
| `orders-mysql` | Banco de dados |
| `orders-rabbitmq` | Message broker (porta 5672 / painel 15672) |
| `orders-worker-classification` | Worker de classificação |
| `orders-worker-audit` | Worker de auditoria |

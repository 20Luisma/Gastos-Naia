# Architecture Decision Records (ADR)

Los ADRs documentan las decisiones arquitectónicas significativas tomadas durante el desarrollo del proyecto. Cada ADR registra el contexto, la decisión y sus consecuencias.

## Formato

Cada ADR sigue la estructura:
- **Estado**: Propuesto / Aceptado / Deprecado / Sustituido
- **Contexto**: Por qué era necesario tomar una decisión
- **Decisión**: Qué se decidió y por qué
- **Consecuencias**: Qué impacto tiene la decisión

---

## Índice

| ID | Título | Estado |
|----|--------|--------|
| [ADR-001](./ADR-001-clean-architecture.md) | Adoptar Clean Architecture | ✅ Aceptado |
| [ADR-002](./ADR-002-google-sheets-as-database.md) | Usar Google Sheets como Base de Datos | ✅ Aceptado |
| [ADR-003](./ADR-003-github-actions-cicd.md) | CI/CD con GitHub Actions + rsync | ✅ Aceptado |
| [ADR-004](./ADR-004-php-dotenv.md) | Gestión de Secretos con phpdotenv | ✅ Aceptado |

---

> Para añadir un nuevo ADR, crea un archivo `ADR-XXX-titulo-descriptivo.md` siguiendo el formato existente y añádelo al índice de esta tabla.

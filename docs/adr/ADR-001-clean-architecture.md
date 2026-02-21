# ADR-001: Adoptar Clean Architecture

| Campo | Valor |
|-------|-------|
| **Estado** | ✅ Aceptado |
| **Fecha** | 2025-01 |
| **Autores** | Equipo Gastos Naia |

---

## Contexto

La aplicación necesita conectarse a Google Sheets API y Google Drive API para leer y escribir datos. Sin una estructura clara, el código de acceso a la API quedaría mezclado con la lógica de negocio y la presentación, haciendo la aplicación difícil de mantener y testear.

## Decisión

Adoptamos **Clean Architecture** con cuatro capas claramente definidas:

1. **Domain** — Entidades y contratos (interfaces de repositorio)
2. **Application** — Casos de uso y orquestación
3. **Infrastructure** — Implementaciones concretas (Google API)
4. **Presentation** — HTTP controller y templates

La regla de dependencia es estricta: las capas internas **nunca** dependen de las externas.

## Consecuencias

**Positivas:**
- El código de Google API está aislado en Infrastructure; si cambia la API, solo cambia esa capa
- Los casos de uso son testables de forma unitaria con mocks de los repositorios
- Separación clara de responsabilidades facilita el onboarding

**Negativas:**
- Mayor cantidad de archivos y abstracciones para una app pequeña
- Curva de aprendizaje inicial más pronunciada

## Alternativas consideradas

| Alternativa | Razón de descarte |
|-------------|-------------------|
| PHP procedimental en un único archivo | No escalable, mezcla responsabilidades |
| MVC simple | Mezclaría lógica de API con controladores |

# Reach Structured Data Architecture

## Overview

The Structured Data subsystem generates, validates, and embeds JSON-LD schema markup in published content. All schemas must pass `StructuredDataValidator` before inclusion in a publication payload. No fake reviews, prices, or aggregate ratings are ever emitted.

---

## Allowed Schema Types

Only the following 10 `@type` values are permitted:

| Schema Type | Use Case |
|-------------|---------|
| `Article` | General articles |
| `BlogPosting` | Blog posts |
| `TechArticle` | Technical documentation |
| `HowTo` | Step-by-step guides |
| `FAQPage` | FAQ articles |
| `BreadcrumbList` | Navigation breadcrumbs |
| `Organization` | Company/brand pages |
| `Person` | Author profiles |
| `WebPage` | Generic web pages |
| `SoftwareApplication` | Software product pages |

All other types (`Product`, `LocalBusiness`, `JobPosting`, `Event`, etc.) are explicitly rejected.

---

## Prohibited Properties

The following properties are rejected regardless of schema type:

| Property | Reason |
|----------|--------|
| `aggregateRating` | Fake/synthetic ratings not permitted |
| `review` | Fake reviews not permitted |
| `offers` | Pricing data not permitted |
| `price` | Pricing data not permitted |
| `priceRange` | Pricing data not permitted |

---

## Components

### StructuredDataValidator

**Namespace**: `App\Services\Publishing\StructuredDataValidator`

Validates an individual JSON-LD schema object:

- Checks `@context` equals `https://schema.org`
- Checks `@type` is in the allowed list
- Checks no prohibited properties are present
- For `FAQPage`: validates `mainEntity` array is non-empty, each entity has `@type: Question`, `name`, and `acceptedAnswer.text`
- For `HowTo`: validates `step` array is non-empty, each step has `@type: HowToStep` and `text`
- For `Article`/`BlogPosting`/`TechArticle`: validates `headline`, `author`, `datePublished`

### StructuredDataBuilder

**Namespace**: `App\Services\Publishing\StructuredDataBuilder`

Builds valid structured data objects ready for embedding:

- **`buildHowTo(array $data): array`** — Builds `HowTo` schema from title, description, and steps array.
- **`buildFAQPage(array $faqs): array`** — Builds `FAQPage` from array of `{question, answer}` pairs.
- **`buildBreadcrumbs(array $items): array`** — Builds `BreadcrumbList` from `{name, url}` array.
- **`buildWebPage(array $data): array`** — Builds `WebPage` schema.

All builder methods produce schemas that pass `StructuredDataValidator` without modification.

---

## Validation Rules by Type

### FAQPage

```json
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    {
      "@type": "Question",
      "name": "What is GSTR-3B?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "GSTR-3B is a monthly self-declaration..."
      }
    }
  ]
}
```

**Required**: `mainEntity` non-empty, each entity has `@type: Question`, `name`, `acceptedAnswer.text`.

### HowTo

```json
{
  "@context": "https://schema.org",
  "@type": "HowTo",
  "name": "How to file GSTR-3B",
  "step": [
    {
      "@type": "HowToStep",
      "text": "Log in to the GST portal..."
    }
  ]
}
```

**Required**: `name`, `step` array non-empty, each step has `@type: HowToStep` and `text`.

### BreadcrumbList

```json
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    {
      "@type": "ListItem",
      "position": 1,
      "name": "Home",
      "item": "https://aicountly.com"
    }
  ]
}
```

---

## Audit Events

| Event | Trigger |
|-------|---------|
| `structured_data.schema_generated` | Schema built by `StructuredDataBuilder` |
| `structured_data.validation_passed` | Schema passes `StructuredDataValidator` |
| `structured_data.validation_failed` | Schema rejected by validator |
| `structured_data.prohibited_property_blocked` | Attempt to include prohibited property |

---

## Security Notes

- Structured data is validated before inclusion in publication payloads.
- No schema type that could imply price, review, or rating is allowed.
- Builder outputs are deterministic; no user-controlled fields bypass validation.

# Media Asset Inventory — Jorsas Tech

Source: SQLite `media_folders` + `media_files` tables + filesystem scan of `public/storage/`

## DB-Tracked Media (127 files)

| Folder | File Count | Contents |
|--------|-----------|----------|
| `backgrounds/` | 68 | Theme BG images, shapes, patterns (about, banner, breadcrumb, choose, consulting, contact, counter, cta, faq, features, finance, insurance, mask, overview, pricing, project, request, services, team, testimonial) |
| `general/` | 27 | Logo, favicon, author, signature, placeholder, newsletter popup, about images, banner, choose, consulting, contact, estimate, finance, insurance, testimonial images |
| `brands/` | 5 | brand-img01.png through brand-img05.png (partner logos) |
| `icons/` | 3 | quote, rating, testimonial-shape04 |
| `sliders/` | 2 | banner-bg.jpg, banner-bg02.jpg |
| `galleries/` | 6 | 1.png through 6.png |
| `news/` | 12 | 1.png through 12.png (blog post images) |
| `testimonials/` | 4 | 1.png through 4.png (avatar photos) |

## Extra Files on Disk (not in DB)

These are in `public/storage/` but not tracked in the media library DB — likely manually added or from the Next.js app:

| Location | Notable Files |
|----------|---------------|
| `backgrounds/` | Jorsas logos (`jorsas-logo.png`, `jorsas-logo-white.png`, `jorsas-logo-design-w.png`), client logos (`noirtech.png`, `client-3.png`, `payitmonthly2023.png`), real estate photos, stock photos, team photos, career images |
| `careers/` | 1.png–6.png, background-image.jpg |
| `fonts/` | 3 font sets (Sinter, Plus Jakarta Sans) with woff2 files |
| `projects/` | 1.png–9.png |
| `services/` | 1.png–4.png, 5.jpg |
| `teams/` | 5 teams × 4 photos each = 20 files |
| `test.png` | Test image |

## Key Brand Assets

| Asset | Path |
|-------|------|
| Logo (dark) | `general/logo.png` |
| Logo (white) | `general/logo-white.png` |
| Logo (alt) | `backgrounds/jorsas-logo.png` |
| Logo (alt white) | `backgrounds/jorsas-logo-white.png` |
| Favicon | `general/favicon.png` |
| Placeholder | `general/placeholder.png` |
| Newsletter popup | `general/newsletter-popup.jpg` |

## Design Tokens (from theme settings)

| Token | Value |
|-------|-------|
| Primary color | `#0055FF` |
| Primary hover | `#0049DC` |
| Secondary | `#00194C` |
| Heading color | `#00194C` |
| Text color | `#334770` |
| Heading font | Urbanist |
| Body font | Plus Jakarta Sans |

# Jorsas Tech — Route & Sitemap Tracking Sheet

Generated: 2026-07-03 | Source: Laravel `php artisan route:list` + SQLite content export

## Frontend (Public) Routes

| # | Route | Purpose | Keep / Merge / Drop | Figma page mapped? | Built in new Next.js? |
|---|---|---|---|---|---|
| 1 | `/` | Homepage (Page ID 1) | Keep | | |
| 2 | `/about` | About page (ID 6) | Keep | | |
| 3 | `/about-2` | About layout alt (ID 7) | Merge w/ `/about` | | |
| 4 | `/about-3` | About layout alt (ID 8) | Merge w/ `/about` | | |
| 5 | `/about-4` | About layout alt (ID 9) | Merge w/ `/about` | | |
| 6 | `/about-5` | About layout alt (ID 10) | Merge w/ `/about` | | |
| 7 | `/services` | Services list (ID 11) | Keep | | |
| 8 | `/services-2` | Services alt layout (ID 12) | Merge w/ `/services` | | |
| 9 | `/services-3` | Services alt layout (ID 13) | Merge w/ `/services` | | |
| 10 | `/services-4` | Services alt layout (ID 14) | Merge w/ `/services` | | |
| 11 | `/services-5` | Services alt layout (ID 15) | Merge w/ `/services` | | |
| 12 | `/blog` | Blog index (ID 19) | Keep | | |
| 13 | `/blog/{slug}` | Blog post detail | Keep | | |
| 14 | `/contact` | Contact page (ID 20) | Keep | | |
| 15 | `/faqs` | FAQs (ID 28) | Keep | | |
| 16 | `/pricing` | Pricing (ID 25) | Keep | | |
| 17 | `/case-studies` | Case Studies (ID 22) | Keep | | |
| 18 | `/projects` | Projects (ID 27) | Keep | | |
| 19 | `/testimonials` | Testimonials (ID 26) | Keep | | |
| 20 | `/partners` | Partners (ID 24) | Keep | | |
| 21 | `/how-it-work` | How It Works (ID 23) | Keep | | |
| 22 | `/cookies` | Cookie Policy (ID 16) | Keep | | |
| 23 | `/terms-of-service` | Terms (ID 17) | Keep | | |
| 24 | `/privacy-policy` | Privacy Policy (ID 18) | Keep | | |
| 25 | `/coming-soon` | Coming Soon (ID 29) | Drop | | |
| 26 | `/consulting` | Landing page alt (ID 2) | Merge w/ `/` | | |
| 27 | `/insurance` | Landing page alt (ID 3) | Merge w/ `/` | | |
| 28 | `/digital-agency` | Landing page alt (ID 4) | Merge w/ `/` | | |
| 29 | `/business` | Landing page alt (ID 5) | Merge w/ `/` | | |
| 30 | `/company` | Company page (ID 21) | Merge w/ `/about` | | |
| 31 | `/sitemap.xml` | SEO sitemap | Keep | | |
| 32 | `/storage/*` | Media files | Keep | | |

## Content Inventory

### Blog Posts (12 total)
| ID | Slug | Title |
|----|------|-------|
| 1 | `10-strategies-for-business-growth-in-2023` | 10 Strategies for Business Growth in 2023 |
| 2 | `navigating-tax-season-tips-for-small-businesses` | Navigating Tax Season: Tips for Small Businesses |
| 3 | `the-power-of-data-analytics-in-business-decision-making` | The Power of Data Analytics in Business Decision-Making |
| 4 | `how-to-create-a-winning-marketing-plan` | How to Create a Winning Marketing Plan |
| 5 | `mastering-the-art-of-financial-planning-for-entrepreneurs` | Mastering the Art of Financial Planning for Entrepreneurs |
| 6 | `the-role-of-innovation-in-modern-business` | The Role of Innovation in Modern Business |
| 7 | `startup-success-stories-lessons-from-industry-leaders` | Startup Success Stories: Lessons from Industry Leaders |
| 8 | `balancing-act-work-life-integration-for-business-owners` | Balancing Act: Work-Life Integration for Business Owners |
| 9 | `the-impact-of-sustainable-practices-on-business-sustainability` | The Impact of Sustainable Practices on Business |
| 10 | `building-a-strong-employer-brand-for-talent-acquisition` | Building a Strong Employer Brand for Talent Acquisition |
| 11 | `productivity-hacks-for-busy-entrepreneurs` | Productivity Hacks for Busy Entrepreneurs |
| 12 | `the-human-factor-hr-best-practices-for-businesses` | The Human Factor: HR Best Practices for Businesses |

### Categories (9)
Market Research, Business Strategy, Financial Management, Entrepreneurship, Technology, Human Resources, Marketing, Sales, Operations

### Tags (11)
Tags are prefixed under `/tag/{slug}` route

### Galleries (6)
Prefixed under `/galleries/{slug}` route

## Design Tokens (from Botble theme settings)

| Token | Value |
|-------|-------|
| Primary color | `#0055FF` |
| Primary hover | `#0049DC` |
| Secondary color | `#00194C` |
| Heading color | `#00194C` |
| Text color | `#334770` |
| Heading font | Urbanist |
| Body font | Plus Jakarta Sans |
| Logo | `general/logo.png` |
| Favicon | `general/favicon.png` |

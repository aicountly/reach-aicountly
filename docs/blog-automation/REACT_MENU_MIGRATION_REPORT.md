# React Menu Migration Report — Blog Command Centre

## Final menu mapping

| Previous menu/route | Final menu/route | Treatment |
|---|---|---|
| Legacy Marketing Blog Management (`/blog`) | Blog Command Centre → Content Pipeline / Drafts | Migrated and deprecated; redirect when `BLOG_LEGACY_REDIRECT_ENABLED` |
| Legacy blog create (`/blog/new`) | Content Studio `/content/new` (blog) / Pipeline | Redirected; API create 403 when flag on |
| Content Studio blog records (`/content?type=blog`) | Blog Command Centre → Content Pipeline | Reused (SHARED) |
| Publishing → Blogs (`/publishing/blogs`) | Blog Command Centre → Publishing / Published | Shared canonical component (ALIAS) |
| Intelligence | Blog Command Centre → SEO and Analytics | Deep-linked/shared |
| AI Control Centre | Blog Command Centre → Operations / Settings | Deep-linked/shared |
| Job Monitor (`/admin/jobs`) | Blog Command Centre → Operations | Deep-linked/shared |
| Approvals (`/approvals`) | Blog Command Centre → Verification and Approvals | Shared |
| Topic Clusters | Blog Command Centre → Roadmap | Deep-linked |
| *(new)* | Blog Command Centre (sidebar root) | NEW section |

## Sidebar items removed

| Item | Section | Proof |
|---|---|---|
| Blog Management | Marketing | Removed from `Sidebar.jsx`; tests assert absence |
| Flow: Blog Posts | Content | Entire Content section removed from Flow `Sidebar.jsx` |

## Orphan check

- Reach `/blog*` routes remain as redirects/aliases only (no parallel CRUD create).
- Flow `content/posts*` API routes removed.
- Permissions: BCC gated on `blog.view` (sidebar) / `blog.view|content.view|publishing.view` (layout). Legacy `blog.view` still valid.
- Publishing top-level module retained for non-blog channels; Blogs leaf unchanged path, same component.

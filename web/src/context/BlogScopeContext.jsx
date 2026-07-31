import { createContext, useContext, useMemo } from 'react';

const BlogScopeContext = createContext(null);

export function BlogScopeProvider({ children }) {
  const value = useMemo(() => ({ contentType: 'blog', blogScoped: true }), []);
  return (
    <BlogScopeContext.Provider value={value}>
      {children}
    </BlogScopeContext.Provider>
  );
}

export function useBlogScope() {
  const ctx = useContext(BlogScopeContext);
  if (!ctx) {
    return { contentType: 'blog', blogScoped: false };
  }
  return ctx;
}

import { HelpCategory, HelpArticle, HelpReleaseNote, HelpSearchResult } from '../types/help';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
const BASE = `${API_URL}/api/help-gateway.php`;

function authHeaders(): HeadersInit {
  const token = localStorage.getItem('auth_token');
  return {
    'Content-Type': 'application/json',
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
  };
}

async function request<T>(url: string, options?: RequestInit): Promise<T> {
  const res = await fetch(url, { headers: authHeaders(), ...options });
  const data = await res.json();
  if (!data.success) throw new Error(data.error || 'Request failed');
  return data;
}

// Public endpoints

export async function fetchCategories(): Promise<HelpCategory[]> {
  const data = await request<{ success: boolean; categories: HelpCategory[] }>(
    `${BASE}?action=categories`
  );
  return data.categories;
}

export async function fetchCategoryArticles(categorySlug: string): Promise<HelpArticle[]> {
  const data = await request<{ success: boolean; articles: HelpArticle[] }>(
    `${BASE}?action=category-articles&category_slug=${encodeURIComponent(categorySlug)}`
  );
  return data.articles;
}

export async function fetchArticle(categorySlug: string, articleSlug: string): Promise<HelpArticle> {
  const data = await request<{ success: boolean; article: HelpArticle }>(
    `${BASE}?action=article&category_slug=${encodeURIComponent(categorySlug)}&slug=${encodeURIComponent(articleSlug)}`
  );
  return data.article;
}

export async function searchArticles(query: string): Promise<HelpSearchResult[]> {
  const data = await request<{ success: boolean; results: HelpSearchResult[] }>(
    `${BASE}?action=search&q=${encodeURIComponent(query)}`
  );
  return data.results;
}

export async function fetchReleaseNotes(page = 1): Promise<{
  release_notes: HelpReleaseNote[];
  total: number;
  page: number;
  per_page: number;
}> {
  return request(`${BASE}?action=release-notes&page=${page}`);
}

export async function fetchReleaseNote(slug: string): Promise<HelpReleaseNote> {
  const data = await request<{ success: boolean; release_note: HelpReleaseNote }>(
    `${BASE}?action=release-note&slug=${encodeURIComponent(slug)}`
  );
  return data.release_note;
}

export async function submitFeedback(articleId: number, isHelpful: boolean, comment?: string): Promise<void> {
  await request(`${BASE}?action=feedback`, {
    method: 'POST',
    body: JSON.stringify({ article_id: articleId, is_helpful: isHelpful, comment }),
  });
}

// Admin endpoints

export async function fetchAdminList(): Promise<{
  articles: HelpArticle[];
  release_notes: HelpReleaseNote[];
  categories: HelpCategory[];
}> {
  return request(`${BASE}?action=admin-list`);
}

export async function createCategory(data: Partial<HelpCategory>): Promise<{ id: number; slug: string }> {
  return request(`${BASE}?action=create-category`, {
    method: 'POST',
    body: JSON.stringify(data),
  });
}

export async function updateCategory(id: number, data: Partial<HelpCategory>): Promise<void> {
  await request(`${BASE}?action=update-category&id=${id}`, {
    method: 'PUT',
    body: JSON.stringify(data),
  });
}

export async function deleteCategory(id: number): Promise<void> {
  await request(`${BASE}?action=delete-category&id=${id}`, { method: 'DELETE' });
}

export async function createArticle(data: Partial<HelpArticle>): Promise<{ id: number; slug: string }> {
  return request(`${BASE}?action=create-article`, {
    method: 'POST',
    body: JSON.stringify(data),
  });
}

export async function updateArticle(id: number, data: Partial<HelpArticle>): Promise<void> {
  await request(`${BASE}?action=update-article&id=${id}`, {
    method: 'PUT',
    body: JSON.stringify(data),
  });
}

export async function deleteArticle(id: number): Promise<void> {
  await request(`${BASE}?action=delete-article&id=${id}`, { method: 'DELETE' });
}

export async function createReleaseNote(data: Partial<HelpReleaseNote>): Promise<{ id: number; slug: string }> {
  return request(`${BASE}?action=create-release-note`, {
    method: 'POST',
    body: JSON.stringify(data),
  });
}

export async function updateReleaseNote(id: number, data: Partial<HelpReleaseNote>): Promise<void> {
  await request(`${BASE}?action=update-release-note&id=${id}`, {
    method: 'PUT',
    body: JSON.stringify(data),
  });
}

export async function deleteReleaseNote(id: number): Promise<void> {
  await request(`${BASE}?action=delete-release-note&id=${id}`, { method: 'DELETE' });
}

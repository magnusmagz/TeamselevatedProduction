export interface HelpCategory {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  icon: string | null;
  sort_order: number;
  role_tag: string | null;
  is_active?: boolean;
  article_count: number;
}

export interface HelpArticle {
  id: number;
  category_id: number;
  title: string;
  slug: string;
  summary: string | null;
  body_markdown: string;
  role_tags: string[];
  related_feature: string | null;
  search_keywords: string[];
  sort_order: number;
  is_published: boolean;
  author_user_id: number;
  created_at: string;
  updated_at: string;
  // Joined fields
  category_name?: string;
  category_slug?: string;
  // Feedback
  user_feedback?: boolean | null;
}

export interface HelpReleaseNote {
  id: number;
  version: string | null;
  title: string;
  slug: string;
  body_markdown: string;
  release_date: string;
  tags: string[];
  is_published: boolean;
  author_user_id: number;
  created_at: string;
  updated_at: string;
}

export interface HelpSearchResult {
  id: number;
  title: string;
  slug: string;
  summary: string | null;
  role_tags: string[];
  category_name: string;
  category_slug: string;
  rank: number;
}

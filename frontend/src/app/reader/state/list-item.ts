import { EntryDto } from '../models';
import { StoryArticleDto, TrendingStoryDto } from '../trending/trending.models';

export type ListItem = EntryDto | TrendingStoryDto;

export function isStory(item: ListItem): item is TrendingStoryDto {
  return 'articles' in item;
}

export function leadOf(item: TrendingStoryDto): StoryArticleDto;
export function leadOf(item: ListItem): EntryDto;
export function leadOf(item: ListItem): EntryDto {
  return isStory(item) ? item.articles[0] : item;
}

export function otherArticlesOf(story: TrendingStoryDto): StoryArticleDto[] {
  return story.articles.slice(1);
}

export function articlesOf(item: ListItem): EntryDto[] {
  return isStory(item) ? item.articles : [item];
}

export function storyOf(item: ListItem): TrendingStoryDto | null {
  return isStory(item) ? item : null;
}

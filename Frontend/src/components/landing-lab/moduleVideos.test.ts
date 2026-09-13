import { describe, expect, it } from 'vitest';
import { youtubeEmbedUrl } from './moduleVideos';

describe('YouTube video configuration', () => {
  it.each([
    'https://www.youtube.com/watch?v=AbCdEfGhI12',
    'https://youtu.be/AbCdEfGhI12?si=example',
    'https://m.youtube.com/watch?v=AbCdEfGhI12&t=10',
    'https://www.youtube.com/shorts/AbCdEfGhI12',
    'https://www.youtube.com/live/AbCdEfGhI12',
    'https://www.youtube-nocookie.com/embed/AbCdEfGhI12',
  ])('accepts a supported YouTube link: %s', link => {
    expect(youtubeEmbedUrl(link)).toBe('https://www.youtube-nocookie.com/embed/AbCdEfGhI12?autoplay=1&rel=0&playsinline=1');
  });

  it.each(['', 'not a URL', 'javascript:alert(1)', 'https://youtube.com.evil.test/watch?v=AbCdEfGhI12', 'https://example.com/embed/AbCdEfGhI12', 'http://youtu.be/AbCdEfGhI12', 'https://youtu.be/short', 'https://user:pass@youtube.com/watch?v=AbCdEfGhI12', 'https://youtube.com:8080/watch?v=AbCdEfGhI12'])('rejects unsafe or incomplete links: %s', link => {
    expect(youtubeEmbedUrl(link)).toBeNull();
  });
});

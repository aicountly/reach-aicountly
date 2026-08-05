import { api } from './api';

export const mediaGalleryService = {
  list: (params) => api.get('v1/media/gallery', params),
  upload: (file, { tags = '', portfolioStream = '', promptUsed = '' } = {}) => {
    const formData = new FormData();
    formData.append('file', file);
    if (tags) formData.append('tags', tags);
    if (portfolioStream) formData.append('portfolio_stream', portfolioStream);
    if (promptUsed) formData.append('prompt_used', promptUsed);
    return api.upload('v1/media/gallery', formData);
  },
  update: (id, body) => api.patch(`v1/media/gallery/${id}`, body),
  deficit: () => api.get('v1/media/gallery/deficit'),
  contentBase: () => api.get('v1/blog/content-base'),
};

export default mediaGalleryService;

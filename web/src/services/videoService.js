import api from './api';

/**
 * Automated video pipeline (routine scripts → Gemini vetting → OpenAI voice-over
 * → Shotstack render → YouTube) and the YouTube channel connection.
 */
export const videoService = {
  pipeline:        (params) => api.get('v1/video/pipeline', params),
  pipelineItem:    (uuid)   => api.get(`v1/video/pipeline/${uuid}`),
  retry:           (uuid)   => api.post(`v1/video/pipeline/${uuid}/retry`),
  status:          ()       => api.get('v1/video/pipeline/status'),
  youtubeStatus:   ()       => api.get('v1/video/youtube/status'),
  youtubeConnect:  ()       => api.get('v1/video/youtube/connect'),
  youtubeDisconnect: ()     => api.post('v1/video/youtube/disconnect'),
};

export default videoService;

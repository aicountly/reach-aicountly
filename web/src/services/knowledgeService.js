import { api } from './api';

const K = 'v1/knowledge';

export const knowledgeService = {
  // Products
  listProducts:    (p)    => api.get(`${K}/products`, p),
  getProduct:      (id)   => api.get(`${K}/products/${id}`),
  createProduct:   (b)    => api.post(`${K}/products`, b),
  updateProduct:   (id,b) => api.put(`${K}/products/${id}`, b),
  deleteProduct:   (id)   => api.delete(`${K}/products/${id}`),
  submitProduct:   (id)   => api.post(`${K}/products/${id}/submit`),
  approveProduct:  (id,b) => api.post(`${K}/products/${id}/approve`, b),
  rejectProduct:   (id,b) => api.post(`${K}/products/${id}/reject`, b),
  archiveProduct:  (id)   => api.post(`${K}/products/${id}/archive`),
  productAliases:  (id)   => api.get(`${K}/products/${id}/aliases`),
  addAlias:        (id,b) => api.post(`${K}/products/${id}/aliases`, b),

  // Modules
  listModules:    (p)    => api.get(`${K}/modules`, p),
  getModule:      (id)   => api.get(`${K}/modules/${id}`),
  createModule:   (b)    => api.post(`${K}/modules`, b),
  updateModule:   (id,b) => api.put(`${K}/modules/${id}`, b),
  deleteModule:   (id)   => api.delete(`${K}/modules/${id}`),
  submitModule:   (id)   => api.post(`${K}/modules/${id}/submit`),
  approveModule:  (id,b) => api.post(`${K}/modules/${id}/approve`, b),
  rejectModule:   (id,b) => api.post(`${K}/modules/${id}/reject`, b),

  // Features
  listFeatures:    (p)    => api.get(`${K}/features`, p),
  getFeature:      (id)   => api.get(`${K}/features/${id}`),
  createFeature:   (b)    => api.post(`${K}/features`, b),
  updateFeature:   (id,b) => api.put(`${K}/features/${id}`, b),
  deleteFeature:   (id)   => api.delete(`${K}/features/${id}`),
  submitFeature:   (id)   => api.post(`${K}/features/${id}/submit`),
  approveFeature:  (id,b) => api.post(`${K}/features/${id}/approve`, b),
  rejectFeature:   (id,b) => api.post(`${K}/features/${id}/reject`, b),

  // Personas
  listPersonas:    (p)    => api.get(`${K}/personas`, p),
  getPersona:      (id)   => api.get(`${K}/personas/${id}`),
  createPersona:   (b)    => api.post(`${K}/personas`, b),
  updatePersona:   (id,b) => api.put(`${K}/personas/${id}`, b),
  deletePersona:   (id)   => api.delete(`${K}/personas/${id}`),
  submitPersona:   (id)   => api.post(`${K}/personas/${id}/submit`),
  approvePersona:  (id,b) => api.post(`${K}/personas/${id}/approve`, b),
  rejectPersona:   (id,b) => api.post(`${K}/personas/${id}/reject`, b),

  // Industries
  listIndustries:    (p)    => api.get(`${K}/industries`, p),
  getIndustry:       (id)   => api.get(`${K}/industries/${id}`),
  createIndustry:    (b)    => api.post(`${K}/industries`, b),
  updateIndustry:    (id,b) => api.put(`${K}/industries/${id}`, b),
  deleteIndustry:    (id)   => api.delete(`${K}/industries/${id}`),
  submitIndustry:    (id)   => api.post(`${K}/industries/${id}/submit`),
  approveIndustry:   (id,b) => api.post(`${K}/industries/${id}/approve`, b),
  rejectIndustry:    (id,b) => api.post(`${K}/industries/${id}/reject`, b),

  // Markets
  listMarkets:    (p)    => api.get(`${K}/markets`, p),
  getMarket:      (id)   => api.get(`${K}/markets/${id}`),
  createMarket:   (b)    => api.post(`${K}/markets`, b),
  updateMarket:   (id,b) => api.put(`${K}/markets/${id}`, b),
  deleteMarket:   (id)   => api.delete(`${K}/markets/${id}`),

  // Business Problems
  listProblems:          (p)    => api.get(`${K}/problems`, p),
  listBusinessProblems:  (p)    => api.get(`${K}/problems`, p), // legacy alias
  getProblem:      (id)   => api.get(`${K}/problems/${id}`),
  createProblem:   (b)    => api.post(`${K}/problems`, b),
  updateProblem:   (id,b) => api.put(`${K}/problems/${id}`, b),
  deleteProblem:   (id)   => api.delete(`${K}/problems/${id}`),

  // Search Intents
  listIntents:        (p)    => api.get(`${K}/search-intents`, p),
  listSearchIntents:  (p)    => api.get(`${K}/search-intents`, p), // legacy alias
  getIntent:      (id)   => api.get(`${K}/search-intents/${id}`),
  createIntent:   (b)    => api.post(`${K}/search-intents`, b),
  updateIntent:   (id,b) => api.put(`${K}/search-intents/${id}`, b),
  deleteIntent:   (id)   => api.delete(`${K}/search-intents/${id}`),
  submitIntent:   (id)   => api.post(`${K}/search-intents/${id}/submit`),
  approveIntent:  (id,b) => api.post(`${K}/search-intents/${id}/approve`, b),
  rejectIntent:   (id,b) => api.post(`${K}/search-intents/${id}/reject`, b),
  syncIntentRelations: (id,b) => api.post(`${K}/search-intents/${id}/sync-relations`, b),

  // Topic Clusters
  listClusters:       (p)    => api.get(`${K}/topic-clusters`, p),
  listTopicClusters:  (p)    => api.get(`${K}/topic-clusters`, p), // legacy alias
  getCluster:         (id)   => api.get(`${K}/topic-clusters/${id}`),
  createCluster:      (b)    => api.post(`${K}/topic-clusters`, b),
  updateCluster:      (id,b) => api.put(`${K}/topic-clusters/${id}`, b),
  deleteCluster:      (id)   => api.delete(`${K}/topic-clusters/${id}`),

  // Claims
  listClaims:    (p)    => api.get(`${K}/claims`, p),
  getClaim:      (id)   => api.get(`${K}/claims/${id}`),
  createClaim:   (b)    => api.post(`${K}/claims`, b),
  updateClaim:   (id,b) => api.put(`${K}/claims/${id}`, b),
  deleteClaim:   (id)   => api.delete(`${K}/claims/${id}`),
  submitClaim:   (id)   => api.post(`${K}/claims/${id}/submit`),
  approveClaim:  (id,b) => api.post(`${K}/claims/${id}/approve`, b),
  rejectClaim:   (id,b) => api.post(`${K}/claims/${id}/reject`, b),
  syncEvidence:  (id,b) => api.post(`${K}/claims/${id}/sync-evidence`, b),

  // Sources
  listSources:    (p)    => api.get(`${K}/sources`, p),
  getSource:      (id)   => api.get(`${K}/sources/${id}`),
  createSource:   (b)    => api.post(`${K}/sources`, b),
  updateSource:   (id,b) => api.put(`${K}/sources/${id}`, b),
  deleteSource:   (id)   => api.delete(`${K}/sources/${id}`),
  submitSource:   (id)   => api.post(`${K}/sources/${id}/submit`),
  approveSource:  (id,b) => api.post(`${K}/sources/${id}/approve`, b),
  rejectSource:   (id,b) => api.post(`${K}/sources/${id}/reject`, b),

  // Citations
  listCitations:    (p)    => api.get(`${K}/citations`, p),
  getCitation:      (id)   => api.get(`${K}/citations/${id}`),
  createCitation:   (b)    => api.post(`${K}/citations`, b),
  updateCitation:   (id,b) => api.put(`${K}/citations/${id}`, b),
  deleteCitation:   (id)   => api.delete(`${K}/citations/${id}`),

  // Brand Rules
  listBrandRules:    (p)    => api.get(`${K}/brand-rules`, p),
  getBrandRule:      (id)   => api.get(`${K}/brand-rules/${id}`),
  createBrandRule:   (b)    => api.post(`${K}/brand-rules`, b),
  updateBrandRule:   (id,b) => api.put(`${K}/brand-rules/${id}`, b),
  deleteBrandRule:   (id)   => api.delete(`${K}/brand-rules/${id}`),
  approveBrandRule:  (id,b) => api.post(`${K}/brand-rules/${id}/approve`, b),
  rejectBrandRule:   (id,b) => api.post(`${K}/brand-rules/${id}/reject`, b),

  // Content Policies
  listPolicies:          (p)    => api.get(`${K}/content-policies`, p),
  listContentPolicies:   (p)    => api.get(`${K}/content-policies`, p), // legacy alias
  getPolicy:       (id)   => api.get(`${K}/content-policies/${id}`),
  createPolicy:    (b)    => api.post(`${K}/content-policies`, b),
  updatePolicy:    (id,b) => api.put(`${K}/content-policies/${id}`, b),
  deletePolicy:    (id)   => api.delete(`${K}/content-policies/${id}`),
  approvePolicy:   (id,b) => api.post(`${K}/content-policies/${id}/approve`, b),
  rejectPolicy:    (id,b) => api.post(`${K}/content-policies/${id}/reject`, b),

  // Grounding
  groundingProduct: (slug) => api.get(`${K}/grounding/product/${slug}`),
  groundingIntent:  (id)   => api.get(`${K}/grounding/intent/${id}`),
  groundingContext: (b)    => api.post(`${K}/grounding/context`, b),

  // Completeness scoring
  completenessAll:     ()   => api.get(`${K}/completeness`),
  completenessProduct: (id) => api.get(`${K}/completeness/product/${id}`),
};

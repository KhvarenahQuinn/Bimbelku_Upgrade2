export type ImageFetchPriority = "high" | "low" | "auto";

/**
 * React 18 types expose `fetchPriority`, but its DOM attribute is lowercase.
 * Passing the camelCase prop to an <img> triggers React's unknown-prop warning,
 * so keep the value in a spread and let React forward `fetchpriority`.
 */
export const imageFetchPriority = (priority: ImageFetchPriority) => ({ fetchpriority: priority });

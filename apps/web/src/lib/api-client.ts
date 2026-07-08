export class ApiClient {
  static async fetch<T>(endpoint: string, options?: RequestInit): Promise<T> {
    const res = await fetch(`/api/v1${endpoint}`, {
      ...options,
      headers: {
        "Content-Type": "application/json",
        ...options?.headers,
      },
    });

    if (!res.ok) {
      let errorMsg = "API Request failed";
      try {
        const errorData = await res.json();
        errorMsg = errorData.message || errorMsg;
      } catch (e) {
        // Ignore JSON parse error if response is not JSON
      }
      throw new Error(errorMsg);
    }

    if (res.status === 204) {
      return {} as T;
    }

    return res.json();
  }

  static async get<T>(endpoint: string, headers?: HeadersInit): Promise<T> {
    return this.fetch<T>(endpoint, { method: "GET", headers });
  }

  static async post<T>(endpoint: string, data?: any, headers?: HeadersInit): Promise<T> {
    return this.fetch<T>(endpoint, {
      method: "POST",
      body: JSON.stringify(data),
      headers,
    });
  }
}

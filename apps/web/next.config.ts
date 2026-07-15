import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  allowedDevOrigins: [
    "erp-ai-dev-tunnel.loca.lt",
    "spicy-kiwis-smell.loca.lt",
    "good-shoes-roll.loca.lt",
    "light-mule-62.loca.lt",
    "little-taxes-walk.loca.lt",
    "ninety-lions-remain.loca.lt",
    "localhost:3000",
    "127.0.0.1:3000"
  ],
  async rewrites() {
    return [
      {
        source: "/api/:path*",
        destination: `${process.env.NEXT_PUBLIC_API_URL || 'http://localhost:4000'}/api/:path*`, // Proxy to Backend
      },
    ];
  },
};

export default nextConfig;

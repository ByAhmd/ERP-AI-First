import Link from "next/link";

export default function Home() {
  return (
    <div className="min-h-screen flex flex-col items-center justify-center p-8 text-center animate-fade-in">
      <h1 className="heading-1 mb-4 text-6xl">ERP AI</h1>
      <p className="text-xl text-secondary max-w-2xl mb-12">
        The intelligent, Saudi-first corporate operating system.
      </p>
      
      <div className="flex gap-6">
        <Link 
          href="/login" 
          className="glass-panel px-8 py-4 text-primary font-medium hover:bg-[rgba(255,255,255,0.05)] transition-all"
        >
          Sign In
        </Link>
        <Link 
          href="/dashboard" 
          className="glass-panel px-8 py-4 text-primary font-medium bg-accent-primary hover:bg-accent-primary-hover border-transparent transition-all"
        >
          Go to Dashboard
        </Link>
      </div>
    </div>
  );
}

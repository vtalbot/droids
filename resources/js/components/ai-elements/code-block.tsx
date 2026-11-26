"use client";
// Lightweight stand-in for the AI Elements CodeBlock (no Shiki) to keep the prototype bundle small.
import { cn } from "@/lib/utils";
import type { HTMLAttributes } from "react";

export type CodeBlockProps = HTMLAttributes<HTMLDivElement> & {
  code: string;
  language?: string;
  showLineNumbers?: boolean;
};

export const CodeBlock = ({ code, language, className, children, ...props }: CodeBlockProps) => (
  <div
    className={cn("relative w-full overflow-hidden rounded-md border bg-muted/40 text-foreground", className)}
    data-language={language}
    {...props}
  >
    <pre className="overflow-x-auto p-3 font-mono text-xs leading-relaxed"><code>{code}</code></pre>
    {children}
  </div>
);

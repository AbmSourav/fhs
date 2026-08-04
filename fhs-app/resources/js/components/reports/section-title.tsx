/** Names a group of figures on the report. */
export default function SectionTitle({ children }: { children: React.ReactNode }) {
    return <h2 className="text-muted-foreground text-md font-medium tracking-wide uppercase">{children}</h2>;
}

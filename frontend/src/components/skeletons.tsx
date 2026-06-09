import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";

// ── Body pieces (reused by route loading.tsx and pages' own loading states) ──

/** List rows placeholder — used as a page's content-area loading state. */
export function SkeletonRows({ count = 6 }: { count?: number }) {
  return (
    <div className="space-y-2" role="status" aria-label="Loading">
      {Array.from({ length: count }).map((_, i) => (
        <Card key={i} className="overflow-hidden">
          <div className="flex items-center justify-between gap-3 px-4 py-3.5">
            <div className="min-w-0 flex-1 space-y-2">
              <Skeleton className="h-4 w-1/3" />
              <Skeleton className="h-3 w-1/2" />
            </div>
            <Skeleton className="h-5 w-16 rounded-full" />
          </div>
        </Card>
      ))}
    </div>
  );
}

function TabsSkeleton() {
  return <Skeleton className="h-9 w-full max-w-sm" />;
}

function DetailCardSkeleton() {
  return (
    <Card>
      <CardContent className="pt-6">
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
          {Array.from({ length: 8 }).map((_, i) => (
            <div key={i} className="space-y-1.5">
              <Skeleton className="h-3 w-16" />
              <Skeleton className="h-4 w-24" />
            </div>
          ))}
        </div>
      </CardContent>
    </Card>
  );
}

function FormCardSkeleton({ fields = 6 }: { fields?: number }) {
  return (
    <Card>
      <CardContent className="space-y-4 pt-6">
        {Array.from({ length: fields }).map((_, i) => (
          <div key={i} className="space-y-2">
            <Skeleton className="h-3.5 w-24" />
            <Skeleton className="h-9 w-full" />
          </div>
        ))}
        <div className="flex justify-end gap-2 pt-2">
          <Skeleton className="h-9 w-20" />
          <Skeleton className="h-9 w-28" />
        </div>
      </CardContent>
    </Card>
  );
}

function KpiCardsSkeleton() {
  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {Array.from({ length: 6 }).map((_, i) => (
        <Card key={i}>
          <div className="space-y-3 p-5">
            <div className="flex items-start justify-between">
              <Skeleton className="h-4 w-24" />
              <Skeleton className="size-8 rounded-lg" />
            </div>
            <Skeleton className="h-8 w-28" />
          </div>
        </Card>
      ))}
    </div>
  );
}

function ChartsSkeleton() {
  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <Skeleton className="h-64 w-full rounded-xl" />
      <Skeleton className="h-64 w-full rounded-xl" />
    </div>
  );
}

/** Header (title + subtitle, optional action). */
function HeaderSkeleton({ withAction = true }: { withAction?: boolean }) {
  return (
    <div className="flex items-center justify-between gap-3">
      <div className="space-y-2">
        <Skeleton className="h-7 w-44" />
        <Skeleton className="h-4 w-56" />
      </div>
      {withAction && <Skeleton className="h-9 w-28 shrink-0" />}
    </div>
  );
}

// ── Content-area skeletons (for a page's own client-data loading state) ──

/** Tabbed detail body (header + tabs + card), without the back link. */
export function DetailBodySkeleton() {
  return (
    <div className="space-y-6" role="status" aria-label="Loading">
      <div className="space-y-2">
        <Skeleton className="h-7 w-1/2" />
        <Skeleton className="h-4 w-1/3" />
      </div>
      <TabsSkeleton />
      <DetailCardSkeleton />
    </div>
  );
}

/** Form body (title + form card), without the back link. */
export function FormBodySkeleton({ fields = 6 }: { fields?: number }) {
  return (
    <div className="space-y-6" role="status" aria-label="Loading">
      <Skeleton className="h-7 w-48" />
      <FormCardSkeleton fields={fields} />
    </div>
  );
}

/** Dashboard/analytics body (KPI cards + charts), without the header. */
export function DashboardBodySkeleton() {
  return (
    <div className="space-y-6" role="status" aria-label="Loading">
      <KpiCardsSkeleton />
      <ChartsSkeleton />
    </div>
  );
}

// ── Full-page skeletons (for route-level loading.tsx) ──

export function ListPageSkeleton({ rows = 6 }: { rows?: number }) {
  return (
    <div className="space-y-6">
      <HeaderSkeleton />
      <TabsSkeleton />
      <SkeletonRows count={rows} />
    </div>
  );
}

export function DetailPageSkeleton() {
  return (
    <div className="space-y-6">
      <Skeleton className="h-4 w-28" />
      <DetailBodySkeleton />
    </div>
  );
}

export function FormSkeleton({ fields = 6 }: { fields?: number }) {
  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <Skeleton className="h-4 w-28" />
      <FormBodySkeleton fields={fields} />
    </div>
  );
}

export function DashboardSkeleton() {
  return (
    <div className="space-y-6">
      <HeaderSkeleton withAction={false} />
      <DashboardBodySkeleton />
    </div>
  );
}

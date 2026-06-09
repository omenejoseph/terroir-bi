"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { ordersApi } from "@/lib/api/orders";
import type {
  ConsignmentReturnInput,
  ConsignmentSaleInput,
  OrderInput,
  OrderItemInput,
  OrderItemUnit,
  OrderQuery,
  OrderStatus,
} from "@/lib/types";

export function useOrders(query: OrderQuery = {}) {
  return useQuery({
    queryKey: ["orders", query],
    queryFn: () => ordersApi.list(query),
  });
}

export function useOrder(id: string | undefined) {
  return useQuery({
    queryKey: ["orders", "item", id],
    queryFn: () => ordersApi.get(id!),
    enabled: !!id,
  });
}

export function useOrderAnalytics(params: { period?: string; from?: string; to?: string }, enabled = true) {
  return useQuery({
    queryKey: ["orders", "analytics", params],
    queryFn: () => ordersApi.analytics(params),
    enabled,
  });
}

export function useOrderConsignment(id: string | undefined, enabled = true) {
  return useQuery({
    queryKey: ["orders", "consignment", id],
    queryFn: () => ordersApi.consignment(id!),
    enabled: !!id && enabled,
  });
}

/** Invalidate the detail row and the list (totals/status may change). */
function useOrderInvalidator(id?: string) {
  const queryClient = useQueryClient();
  return () => {
    void queryClient.invalidateQueries({ queryKey: ["orders"] });
    if (id) void queryClient.invalidateQueries({ queryKey: ["orders", "item", id] });
  };
}

export function useCreateOrder() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: OrderInput) => ordersApi.create(input),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["orders"] }),
  });
}

export function useUpdateOrderStatus(id: string) {
  const invalidate = useOrderInvalidator(id);
  return useMutation({
    mutationFn: (input: { status: OrderStatus; note?: string | null }) =>
      ordersApi.updateStatus(id, input),
    onSuccess: invalidate,
  });
}

export function useAddOrderItems(id: string) {
  const invalidate = useOrderInvalidator(id);
  return useMutation({
    mutationFn: (items: OrderItemInput[]) => ordersApi.addItems(id, items),
    onSuccess: invalidate,
  });
}

export function useUpdateOrderItem(orderId: string) {
  const invalidate = useOrderInvalidator(orderId);
  return useMutation({
    mutationFn: (vars: { itemId: string; input: { quantity?: number; unit_type?: OrderItemUnit } }) =>
      ordersApi.updateItem(vars.itemId, vars.input),
    onSuccess: invalidate,
  });
}

export function useUpdateOrderItemCost(orderId: string) {
  const invalidate = useOrderInvalidator(orderId);
  return useMutation({
    mutationFn: (vars: { itemId: string; costPerUnit: number | null }) =>
      ordersApi.updateItemCost(vars.itemId, vars.costPerUnit),
    onSuccess: invalidate,
  });
}

export function useDeleteOrderItem(orderId: string) {
  const invalidate = useOrderInvalidator(orderId);
  return useMutation({
    mutationFn: (itemId: string) => ordersApi.deleteItem(itemId),
    onSuccess: invalidate,
  });
}

export function useUpdateShipping(id: string) {
  const invalidate = useOrderInvalidator(id);
  return useMutation({
    mutationFn: (input: { shipping_cost: number | null; shipping_paid_by_us?: boolean }) =>
      ordersApi.updateShipping(id, input),
    onSuccess: invalidate,
  });
}

export function useUpdateNotes(id: string) {
  const invalidate = useOrderInvalidator(id);
  return useMutation({
    mutationFn: (notes: string | null) => ordersApi.updateNotes(id, notes),
    onSuccess: invalidate,
  });
}

export function useUpdateBackorder(id: string) {
  const invalidate = useOrderInvalidator(id);
  return useMutation({
    mutationFn: (backorderDate: string | null) => ordersApi.updateBackorder(id, backorderDate),
    onSuccess: invalidate,
  });
}

export function useAddComment(id: string) {
  const invalidate = useOrderInvalidator(id);
  return useMutation({
    mutationFn: (input: { content: string; mentions?: string[] }) => ordersApi.addComment(id, input),
    onSuccess: invalidate,
  });
}

export function useUpdateComment(orderId: string) {
  const invalidate = useOrderInvalidator(orderId);
  return useMutation({
    mutationFn: (vars: { commentId: string; content: string }) =>
      ordersApi.updateComment(vars.commentId, vars.content),
    onSuccess: invalidate,
  });
}

export function useDeleteComment(orderId: string) {
  const invalidate = useOrderInvalidator(orderId);
  return useMutation({
    mutationFn: (commentId: string) => ordersApi.deleteComment(commentId),
    onSuccess: invalidate,
  });
}

function useConsignmentInvalidator(id: string) {
  const queryClient = useQueryClient();
  return () => {
    void queryClient.invalidateQueries({ queryKey: ["orders", "consignment", id] });
    void queryClient.invalidateQueries({ queryKey: ["orders", "item", id] });
  };
}

export function useConsignmentSale(id: string) {
  const invalidate = useConsignmentInvalidator(id);
  return useMutation({
    mutationFn: (input: ConsignmentSaleInput) => ordersApi.consignmentSale(id, input),
    onSuccess: invalidate,
  });
}

export function useConsignmentReturn(id: string) {
  const invalidate = useConsignmentInvalidator(id);
  return useMutation({
    mutationFn: (input: ConsignmentReturnInput) => ordersApi.consignmentReturn(id, input),
    onSuccess: invalidate,
  });
}

export function useConsignmentClose(id: string) {
  const invalidate = useConsignmentInvalidator(id);
  return useMutation({
    mutationFn: () => ordersApi.consignmentClose(id),
    onSuccess: invalidate,
  });
}

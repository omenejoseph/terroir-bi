"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { teamApi } from "@/lib/api/team";
import type { InviteInput, MemberUpdate } from "@/lib/types";

export function useMembers() {
  return useQuery({ queryKey: ["team", "members"], queryFn: () => teamApi.members() });
}

export function useInvitations() {
  return useQuery({ queryKey: ["team", "invitations"], queryFn: () => teamApi.invitations() });
}

export function useInvite() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: InviteInput) => teamApi.invite(input),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["team", "invitations"] }),
  });
}

export function useRevokeInvitation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => teamApi.revokeInvitation(id),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["team", "invitations"] }),
  });
}

export function useUpdateMember() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (vars: { userId: string; input: MemberUpdate }) =>
      teamApi.updateMember(vars.userId, vars.input),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["team", "members"] }),
  });
}

export function useRemoveMember() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (userId: string) => teamApi.removeMember(userId),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["team", "members"] }),
  });
}

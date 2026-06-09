import { api } from "@/lib/api/client";
import type { Invitation, InviteInput, Member, MemberUpdate } from "@/lib/types";

/** Members & invitations (IAM). Mirrors routes/api.php (members/*, invitations/*). */
export const teamApi = {
  /** GET /members — everyone in the active tenant. */
  members: () => api.get<Member[]>("/members"),

  /** PATCH /members/{userId} — change roles and/or status. */
  updateMember: (userId: string, input: MemberUpdate) =>
    api.patch<Member>(`/members/${userId}`, input),

  /** DELETE /members/{userId} — remove from the tenant. */
  removeMember: (userId: string) => api.delete<void>(`/members/${userId}`),

  /** GET /invitations — pending invitations. */
  invitations: () => api.get<Invitation[]>("/invitations"),

  /** POST /invitations — invite a user. Response includes a one-time accept_token. */
  invite: (input: InviteInput) => api.post<Invitation>("/invitations", input),

  /** DELETE /invitations/{id} — revoke a pending invitation. */
  revokeInvitation: (id: string) => api.delete<void>(`/invitations/${id}`),
};
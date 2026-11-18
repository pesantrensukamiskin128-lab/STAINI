import api from './api'

export interface LoginCredentials {
  identifier?: string
  email?: string
  password: string
}

export interface AuthUser {
  id: number
  name: string
  email: string
  username: string
  photo_path?: string | null
  photo_url?: string | null
  is_active: boolean
  last_login_at: string | null
  roles: string[]
  permissions: string[]
  position_codes?: string[]
  student?: { id: number; nim: string; name: string; study_program_id?: number; status?: string } | null
  lecturer?: { id: number; name: string } | null
}

export interface UpdateProfilePayload {
  name?: string
  email?: string
  username?: string
  current_password?: string
  new_password?: string
  new_password_confirmation?: string
}

export interface LoginResponse {
  access_token: string
  token_type: string
  user: AuthUser
}

const AuthService = {
  async login(credentials: LoginCredentials): Promise<LoginResponse> {
    const { data } = await api.post<LoginResponse>('/auth/login', {
      ...credentials,
      identifier: credentials.identifier ?? credentials.email,
    })
    return data
  },

  async me(): Promise<AuthUser> {
    const { data } = await api.get<AuthUser>('/auth/me')
    return data
  },

  async updateProfile(payload: UpdateProfilePayload): Promise<{ message: string; user: AuthUser }> {
    const { data } = await api.put<{ message: string; user: AuthUser }>('/auth/profile', payload)
    return data
  },

  async uploadProfilePhoto(photo: File): Promise<{ message: string; user: Pick<AuthUser, 'photo_path' | 'photo_url'> }> {
    const formData = new FormData()
    formData.append('photo', photo)
    const { data } = await api.post('/auth/profile/photo', formData)
    return data
  },

  async logout(): Promise<void> {
    await api.post('/auth/logout')
  },
}

export default AuthService

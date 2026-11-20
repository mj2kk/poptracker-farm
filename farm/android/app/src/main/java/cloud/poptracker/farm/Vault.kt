package cloud.poptracker.farm

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import org.json.JSONObject
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

internal class Vault(context: Context) {
    private val prefs = context.getSharedPreferences("enrollment", Context.MODE_PRIVATE)
    private val alias = "farm-enrollment-v1"
    private val keys get() = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
    private fun key(): SecretKey {
        (keys.getKey(alias, null) as? SecretKey)?.let { return it }
        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        generator.init(KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
            .setBlockModes(KeyProperties.BLOCK_MODE_GCM).setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE).build())
        return generator.generateKey()
    }
    fun save(c: Credentials) {
        val plain = JSONObject().put("origin", c.origin).put("id", c.id).put("token", c.token)
        writeSealed("", plain)
    }
    fun writeSealed(prefix: String, plain: JSONObject) {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, key())
        val bytes = cipher.doFinal(plain.toString().toByteArray(Charsets.UTF_8))
        check(prefs.edit().putString(prefix + "iv", Base64.encodeToString(cipher.iv, Base64.NO_WRAP))
            .putString(prefix + "sealed", Base64.encodeToString(bytes, Base64.NO_WRAP)).commit())
    }
    fun readSealed(prefix: String): JSONObject? {
        val sealed = prefs.getString(prefix + "sealed", null) ?: return null
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        val iv = Base64.decode(prefs.getString(prefix + "iv", null), Base64.NO_WRAP)
        cipher.init(Cipher.DECRYPT_MODE, keys.getKey(alias, null), GCMParameterSpec(128, iv))
        return JSONObject(String(cipher.doFinal(Base64.decode(sealed, Base64.NO_WRAP)), Charsets.UTF_8))
    }
    fun read(): Credentials? {
        val c = readSealed("") ?: return null
        return Credentials(validatedOrigin(c.getString("origin")), c.getString("id"), c.getString("token"))
    }
    var lastBeat: String
        get() = prefs.getString("last", null) ?: "—"
        set(value) { prefs.edit().putString("last", value).apply() }
    fun clear() {
        check(prefs.edit().clear().commit())
        keys.deleteEntry(alias)
    }
}

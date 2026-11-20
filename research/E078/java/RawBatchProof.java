package app.morphe.extension.tiktok.interaction;
import org.json.*;
import java.nio.ByteBuffer;
import java.nio.charset.StandardCharsets;
import java.nio.charset.CodingErrorAction;
import java.util.*;

/** Exact already-buffered public author wire fields, separate from decoded model defaults. */
final class RawBatchProof {
 static final class Snapshot {
  Integer http;Long length;boolean valid,empty,fatal,identityValid=true;String classification="not_observed";
  boolean JSONCompleteObject,statusCodePresent,errorCodePresent;
  String statusCodeState="not_parsed",errorCodeState="not_parsed",statusCodeType,errorCodeType;
  Long statusCodeValue,errorCodeValue;
  final List<JSONObject> records=new ArrayList<>();
  JSONObject summary()throws Exception{return new JSONObject().put("classification",classification).put("http_status",http==null?JSONObject.NULL:http).put("SDK_byte_array_length",length==null?JSONObject.NULL:length).put("valid_wire_proof",valid).put("identity_valid",identityValid).put("explicit_fatal_wire_signal",fatal).put("wire_author_records",records.size()).put("wire_JSON_complete_object",JSONCompleteObject).put("wire_status_code_present",statusCodePresent).put("wire_status_code_state",statusCodeState).put("wire_status_code_value",statusCodeValue==null?JSONObject.NULL:statusCodeValue).put("wire_status_code_type",statusCodeType==null?JSONObject.NULL:statusCodeType).put("wire_error_code_present",errorCodePresent).put("wire_error_code_state",errorCodeState).put("wire_error_code_value",errorCodeValue==null?JSONObject.NULL:errorCodeValue).put("wire_error_code_type",errorCodeType==null?JSONObject.NULL:errorCodeType).put("original_buffer_modified",false).put("body_or_stream_consumed",false).put("body_exported",false);}
 }
 static Snapshot capture(Object raw,Map<String,String> expected)throws Exception {
  Snapshot s=new Snapshot();Object status=field(raw,"LIZIZ");s.http=status instanceof Integer?(Integer)status:null;
  if(s.http==null||s.http!=200){if(s.http!=null&&(s.http==403||s.http==429))s.fatal=true;s.classification="HTTP_not200_or_unknown";return s;}
  Object body=field(raw,"LJ");if(body==null||!"com.bytedance.retrofit2.mime.TypedByteArray".equals(body.getClass().getName())){s.classification="not_exact_buffered_body";return s;}
  Object len=body.getClass().getMethod("length").invoke(body);s.length=len instanceof Long?(Long)len:null;
  if(s.length==null||s.length<0||s.length>2097152){s.classification="SDK_buffer_unknown_or_over2MiB";return s;}
  Object bytes=body.getClass().getMethod("getBytes").invoke(body);if(!(bytes instanceof byte[])||((byte[])bytes).length!=s.length){s.classification="SDK_buffer_accessor_mismatch";return s;}
  byte[] copy=((byte[])bytes).clone();if(copy.length==0){s.empty=true;s.classification="observed_HTTP200_zero_length_SDK_byte_array";return s;}
  JSONObject root;try{String text=StandardCharsets.UTF_8.newDecoder().onMalformedInput(CodingErrorAction.REPORT).onUnmappableCharacter(CodingErrorAction.REPORT).decode(ByteBuffer.wrap(copy)).toString();JSONTokener t=new JSONTokener(text);Object value=t.nextValue();if(!(value instanceof JSONObject)||t.nextClean()!=0){s.classification="not_complete_JSON_object";return s;}root=(JSONObject)value;}
  catch(Exception e){s.classification="JSON_or_UTF8_parse_error_"+e.getClass().getSimpleName();return s;}
  Object business=root.opt("status_code"),error=root.opt("error_code");boolean bp=root.has("status_code"),ep=root.has("error_code");
  // Export only exact numeric members and type/presence before ANY early return.
  s.JSONCompleteObject=true;s.statusCodePresent=bp;s.errorCodePresent=ep;
  s.statusCodeState=codeState(bp,business);s.errorCodeState=codeState(ep,error);
  s.statusCodeValue=integerCode(business);s.errorCodeValue=integerCode(error);
  s.statusCodeType=bp?codeType(business):null;s.errorCodeType=ep?codeType(error):null;
  for(String key:Arrays.asList("status_msg","message")){Object v=root.opt(key);if(v instanceof String){String q=((String)v).toLowerCase(Locale.ROOT);if(q.contains("captcha")||q.contains("verify")||q.contains("verification")||q.contains("challenge")||q.contains("access denied")||q.contains("rate limit")||q.contains("too many requests")||q.contains("rate_limit")||q.contains("login")||q.contains("log in")||q.contains("验证")||q.contains("驗證")){s.fatal=true;s.classification="wire_security_signal_stop";}}}
  boolean businessSafe=bp&&business instanceof Integer&&((Integer)business)==0&&(!ep||(error instanceof Integer&&((Integer)error)==0));
  if((business instanceof Number&&((Number)business).doubleValue()!=0)||(error instanceof Number&&((Number)error).doubleValue()!=0)){s.fatal=true;if(!"wire_security_signal_stop".equals(s.classification))s.classification="wire_explicit_business_failure";}
  JSONArray details=root.optJSONArray("aweme_details");if(details==null||details.length()>100){if(!s.fatal)s.classification="wire_aweme_details_absent_or_over_cap";return s;}
  Set<String> seen=new HashSet<>();
  for(int i=0;i<details.length();i++){
   JSONObject video=details.optJSONObject(i);if(video==null){s.identityValid=false;continue;}
   Object aid=video.opt("aweme_id");String id=aid instanceof String?(String)aid:null;String expectedUID=expected.get(id);
   if(expectedUID==null||!seen.add(id)){s.identityValid=false;if(id!=null)s.fatal=true;continue;}
   JSONObject author=video.optJSONObject("author");if(author==null)continue;
   Object uv=author.opt("uid");String uid=uv instanceof String?(String)uv:uv instanceof Long||uv instanceof Integer?uv.toString():null;if(!expectedUID.equals(uid)){s.identityValid=false;if(uid!=null)s.fatal=true;continue;}
   JSONObject r=new JSONObject().put("schema","poptracker.raw-author-geo.json-record.v1").put("kind","author_json_observation").put("source","native_original_GET_batch_author_json").put("uid",uid).put("aweme_id",id).put("JSON_user_path","$.aweme_details["+i+"].author").put("region",country(author,"region")).put("account_region",country(author,"account_region")).put("wire_status_code_present",bp).put("wire_status_code",bp?business:JSONObject.NULL).put("wire_business_qualified",businessSafe).put("wire_error_code_present",ep).put("wire_error_code",ep?error:JSONObject.NULL).put("http_status",200).put("UID_and_video_exact_requested_pair",true);
   Object handle=author.opt("unique_id");r.put("handle",handle instanceof String&&((String)handle).matches("[A-Za-z0-9_.]{1,32}")?handle:JSONObject.NULL);s.records.add(r);
  }
  s.valid=s.identityValid&&businessSafe&&!s.fatal;if(s.fatal&&"not_observed".equals(s.classification))s.classification="wire_UID_video_identity_failure";else if(!s.fatal)s.classification=s.valid?"source_confirmed_wire_author_paths_parsed":"wire_proof_incomplete_business_or_identity_missing";return s;
 }
 static JSONObject country(JSONObject user,String key)throws Exception{
  JSONObject cell=new JSONObject().put("json_key",key);if(!user.has(key))return cell.put("status","missing");Object v=user.opt(key);if(v==null||v==JSONObject.NULL)return cell.put("status","null").put("raw",JSONObject.NULL);if(!(v instanceof String))return cell.put("status","malformed_type").put("raw_type",v.getClass().getSimpleName());String q=(String)v;if(q.isEmpty())return cell.put("status","empty").put("raw",q);if(q.matches("[A-Za-z]{2}"))return cell.put("status","value").put("raw",q);if(q.length()<=32&&q.matches("[A-Za-z_ -]+"))return cell.put("status","malformed_country_string").put("raw",q);return cell.put("status","malformed_country_string").put("raw_redacted",true).put("raw_length",q.length());
 }
 private static String codeState(boolean present,Object value){
  if(!present)return "missing";
  if(value==null||value==JSONObject.NULL)return "null";
  if(value instanceof Integer||value instanceof Long)return "integer";
  return value instanceof Number?"non_integer_number":"non_numeric_type";
 }
 private static Long integerCode(Object value){return value instanceof Integer||value instanceof Long?((Number)value).longValue():null;}
 private static String codeType(Object value){return value==null||value==JSONObject.NULL?"null":value.getClass().getSimpleName();}
 private static Object field(Object o,String n)throws Exception{if(o==null)return null;for(Class<?> c=o.getClass();c!=null;c=c.getSuperclass())try{java.lang.reflect.Field f=c.getDeclaredField(n);f.setAccessible(true);return f.get(o);}catch(NoSuchFieldException ignored){}throw new NoSuchFieldException(n);}
}
